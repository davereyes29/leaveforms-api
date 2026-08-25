<?php

namespace App\Services\Leave;

use App\Enums\Leave\LeaveStatus;
use App\Enums\Leave\ReminderKind;
use App\Exceptions\ReminderAlreadySentException;
use App\Mail\Leave\LeaveReminderDigestMail;
use App\Mail\Leave\LeaveReminderMail;
use App\Models\Kdtph\Employee;
use App\Models\Leave\LeaveAdminReminder;
use App\Models\Leave\LeaveInfo;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;

/**
 * Admin nudges for pending_approval leaves (bulk + single leave).
 *
 * Bulk and single are independent once-per-Asia/Manila-day locks.
 * Bulk: one generic email per waiting approver.
 * Single: once per (leave_id, approver_emp_id) with the detailed leave mail.
 */
class AdminReminderService
{
    public function __construct(
        private readonly LeaveApproverResolver $approverResolver,
        private readonly LeaveApproverRecipientService $recipientService,
    ) {}

    /**
     * @return array{
     *     approver_count: int,
     *     request_count: int,
     *     last_sent_at: string|null,
     *     can_send: bool,
     *     singles_locked: bool,
     *     reminded_pairs: list<array{leave_id: int, approver_emp_id: int}>
     * }
     *
     * @throws AuthorizationException
     */
    public function statsForAdmin(Employee $admin): array
    {
        $this->assertAdmin($admin);

        $waiting = $this->waitingByApprover();
        $latestBulk = $this->latestBulk();

        return [
            'approver_count' => $waiting->count(),
            'request_count' => $this->requestCount($waiting),
            'last_sent_at' => $this->formatSentAt($latestBulk),
            'can_send' => $this->notificationsEnabled() && ! $this->sentToday($latestBulk),
            'singles_locked' => false,
            'reminded_pairs' => $this->remindedPairsToday(),
        ];
    }

    /**
     * @return array{approver_count: int, request_count: int, email_count: int, sent_at: string}
     *
     * @throws AuthorizationException
     * @throws ReminderAlreadySentException
     * @throws ValidationException
     */
    public function sendBulk(Employee $admin): array
    {
        $this->assertAdmin($admin);
        $this->assertNotificationsEnabled();
        $this->assertBulkNotSentToday();

        $waiting = $this->waitingByApprover();

        if ($waiting->isEmpty()) {
            throw ValidationException::withMessages([
                'reminder' => 'There are no pending approval requests to remind.',
            ]);
        }

        $emailCount = 0;

        foreach ($waiting as $row) {
            /** @var Employee $approver */
            $approver = $row['approver'];
            $pendingCount = $row['leaves']->unique('id')->count();

            if ($pendingCount === 0) {
                continue;
            }

            try {
                Mail::to($approver->email)->send(
                    new LeaveReminderDigestMail(
                        approverName: $approver->firstname ?: 'there',
                        pendingCount: $pendingCount,
                    ),
                );
                $emailCount++;
            } catch (\Throwable $exception) {
                Log::error('Admin bulk reminder failed.', [
                    'approver_emp_id' => $approver->id,
                    'approver_email' => $approver->email,
                    'error' => $exception->getMessage(),
                ]);
            }
        }

        if ($emailCount === 0) {
            throw ValidationException::withMessages([
                'reminder' => 'No reminder emails could be sent.',
            ]);
        }

        $approverCount = $waiting->count();
        $requestCount = $this->requestCount($waiting);

        $record = $this->record(
            ReminderKind::Bulk,
            $admin,
            $approverCount,
            $requestCount,
            $emailCount,
        );

        return [
            'approver_count' => $approverCount,
            'request_count' => $requestCount,
            'email_count' => $emailCount,
            'sent_at' => $this->formatSentAt($record) ?? $record->sent_at->toIso8601String(),
        ];
    }

    /**
     * @return array{approver_count: int, request_count: int, email_count: int, sent_at: string}
     *
     * @throws AuthorizationException
     * @throws ReminderAlreadySentException
     * @throws ValidationException
     */
    public function sendSingle(LeaveInfo $leave, Employee $admin, int $approverEmpId): array
    {
        $this->assertAdmin($admin);
        $this->assertNotificationsEnabled();
        $this->assertPairNotRemindedToday((int) $leave->id, $approverEmpId);

        if ($leave->status !== LeaveStatus::PendingApproval) {
            throw ValidationException::withMessages([
                'leave' => 'Reminders can only be sent for leaves pending approval.',
            ]);
        }

        if ($approverEmpId === $this->adminEmpId()) {
            throw ValidationException::withMessages([
                'approver_emp_id' => 'Admin is not reminded.',
            ]);
        }

        $leave->loadMissing(['leaveType', 'approvalRequirements']);

        $target = $this->recipientService
            ->forOpenRequirement($leave)
            ->first(fn (Employee $approver) => (int) $approver->id === $approverEmpId);

        if ($target === null) {
            throw ValidationException::withMessages([
                'approver_emp_id' => 'This approver is not waiting on this leave request.',
            ]);
        }

        $employeeOnLeave = Employee::query()->find($leave->emp_id);
        $employeeName = $employeeOnLeave?->fullName() ?: 'An employee';

        try {
            Mail::to($target->email)->send(
                new LeaveReminderMail(
                    leave: $leave,
                    employeeName: $employeeName,
                    approverName: $target->firstname ?: 'there',
                ),
            );
        } catch (\Throwable $exception) {
            Log::error('Admin single reminder failed.', [
                'leave_id' => $leave->id,
                'approver_emp_id' => $target->id,
                'approver_email' => $target->email,
                'error' => $exception->getMessage(),
            ]);

            throw ValidationException::withMessages([
                'reminder' => 'No reminder emails could be sent.',
            ]);
        }

        $record = $this->record(
            ReminderKind::Single,
            $admin,
            1,
            1,
            1,
            (int) $leave->id,
            (int) $target->id,
        );

        return [
            'approver_count' => 1,
            'request_count' => 1,
            'email_count' => 1,
            'sent_at' => $this->formatSentAt($record) ?? $record->sent_at->toIso8601String(),
        ];
    }

    /**
     * @throws AuthorizationException
     */
    private function assertAdmin(Employee $admin): void
    {
        if (! $this->approverResolver->contextFor($admin->id)->isAdmin) {
            throw new AuthorizationException('Only admin can send approval reminders.');
        }
    }

    /**
     * @throws ValidationException
     */
    private function assertNotificationsEnabled(): void
    {
        if (! $this->notificationsEnabled()) {
            throw ValidationException::withMessages([
                'reminder' => 'Email notifications are disabled.',
            ]);
        }
    }

    /**
     * @throws ReminderAlreadySentException
     */
    private function assertBulkNotSentToday(): void
    {
        $latestBulk = $this->latestBulk();

        if ($this->sentToday($latestBulk)) {
            throw new ReminderAlreadySentException(
                $this->formatSentAt($latestBulk),
                'An org-wide reminder was already sent today.',
            );
        }
    }

    /**
     * @throws ReminderAlreadySentException
     */
    private function assertPairNotRemindedToday(int $leaveId, int $approverEmpId): void
    {
        $existing = $this->singleToday($leaveId, $approverEmpId);

        if ($existing !== null) {
            throw new ReminderAlreadySentException(
                $this->formatSentAt($existing),
                'This approver was already reminded for this leave today.',
            );
        }
    }

    /**
     * Waiting approvers (excluding admin) keyed by emp_id.
     *
     * @return Collection<int, array{approver: Employee, leaves: Collection<int, LeaveInfo>}>
     */
    private function waitingByApprover(): Collection
    {
        $adminId = $this->adminEmpId();

        $leaves = LeaveInfo::query()
            ->with(['approvalRequirements'])
            ->where('status', LeaveStatus::PendingApproval)
            ->orderBy('start_date')
            ->orderBy('id')
            ->get();

        $byApprover = [];

        foreach ($leaves as $leave) {
            foreach ($this->recipientService->forOpenRequirement($leave) as $approver) {
                if ((int) $approver->id === $adminId) {
                    continue;
                }

                $empId = (int) $approver->id;

                if (! isset($byApprover[$empId])) {
                    $byApprover[$empId] = [
                        'approver' => $approver,
                        'leaves' => collect(),
                    ];
                }

                $byApprover[$empId]['leaves']->push($leave);
            }
        }

        return collect($byApprover);
    }

    /**
     * @param  Collection<int, array{approver: Employee, leaves: Collection<int, LeaveInfo>}>  $waiting
     */
    private function requestCount(Collection $waiting): int
    {
        return $waiting
            ->flatMap(fn (array $row) => $row['leaves']->pluck('id'))
            ->unique()
            ->count();
    }

    private function record(
        ReminderKind $kind,
        Employee $admin,
        int $approverCount,
        int $requestCount,
        int $emailCount,
        ?int $leaveId = null,
        ?int $targetEmpId = null,
    ): LeaveAdminReminder {
        return LeaveAdminReminder::query()->create([
            'kind' => $kind,
            'sent_by_emp_id' => $admin->id,
            'leave_id' => $leaveId,
            'target_emp_id' => $targetEmpId,
            'approver_count' => $approverCount,
            'request_count' => $requestCount,
            'email_count' => $emailCount,
            'sent_at' => now(),
        ]);
    }

    private function latestBulk(): ?LeaveAdminReminder
    {
        return LeaveAdminReminder::query()
            ->where('kind', ReminderKind::Bulk)
            ->orderByDesc('sent_at')
            ->orderByDesc('id')
            ->first();
    }

    private function singleToday(int $leaveId, int $approverEmpId): ?LeaveAdminReminder
    {
        return $this->remindersOnManilaDay()
            ->where('kind', ReminderKind::Single)
            ->where('leave_id', $leaveId)
            ->where('target_emp_id', $approverEmpId)
            ->orderByDesc('sent_at')
            ->orderByDesc('id')
            ->first();
    }

    /**
     * @return list<array{leave_id: int, approver_emp_id: int}>
     */
    private function remindedPairsToday(): array
    {
        return $this->remindersOnManilaDay()
            ->where('kind', ReminderKind::Single)
            ->whereNotNull('leave_id')
            ->whereNotNull('target_emp_id')
            ->orderBy('id')
            ->get(['leave_id', 'target_emp_id'])
            ->unique(fn (LeaveAdminReminder $row) => $this->pairKey((int) $row->leave_id, (int) $row->target_emp_id))
            ->map(fn (LeaveAdminReminder $row) => [
                'leave_id' => (int) $row->leave_id,
                'approver_emp_id' => (int) $row->target_emp_id,
            ])
            ->values()
            ->all();
    }

    private function pairKey(int $leaveId, int $approverEmpId): string
    {
        return $leaveId.':'.$approverEmpId;
    }

    private function remindersOnManilaDay(): Builder
    {
        [$start, $end] = $this->manilaDayUtcBounds();

        return LeaveAdminReminder::query()
            ->whereBetween('sent_at', [$start, $end]);
    }

    /**
     * @return array{0: \Illuminate\Support\Carbon, 1: \Illuminate\Support\Carbon}
     */
    private function manilaDayUtcBounds(): array
    {
        $timezone = $this->timezone();

        return [
            now($timezone)->startOfDay()->utc(),
            now($timezone)->endOfDay()->utc(),
        ];
    }

    private function sentToday(?LeaveAdminReminder $reminder): bool
    {
        if ($reminder === null || $reminder->sent_at === null) {
            return false;
        }

        $timezone = $this->timezone();

        return $reminder->sent_at->copy()->timezone($timezone)->toDateString()
            === now($timezone)->toDateString();
    }

    private function formatSentAt(?LeaveAdminReminder $reminder): ?string
    {
        if ($reminder === null || $reminder->sent_at === null) {
            return null;
        }

        return $reminder->sent_at->copy()->timezone($this->timezone())->toIso8601String();
    }

    private function notificationsEnabled(): bool
    {
        return (bool) config('leave.notifications.enabled', true);
    }

    private function timezone(): string
    {
        return (string) config('leave.reminders.timezone', 'Asia/Manila');
    }

    private function adminEmpId(): int
    {
        return (int) config('leave.admin_emp_id');
    }
}
