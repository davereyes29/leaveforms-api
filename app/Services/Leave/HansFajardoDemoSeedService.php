<?php

namespace App\Services\Leave;

use App\Enums\Leave\ActivityType;
use App\Enums\Leave\ApproverType;
use App\Enums\Leave\DayType;
use App\Enums\Leave\LeaveStatus;
use App\Enums\Leave\RequiredApproverType;
use App\Models\Kdtph\Employee;
use App\Models\Leave\LeaveActivityHistory;
use App\Models\Leave\LeaveActivityRecipient;
use App\Models\Leave\LeaveApprovalHistory;
use App\Models\Leave\LeaveApprovalRequirement;
use App\Models\Leave\LeaveBalanceTransaction;
use App\Models\Leave\LeaveConversion;
use App\Models\Leave\LeaveConversionAttachment;
use App\Models\Leave\LeaveInfo;
use App\Models\Leave\LeaveInfoAttachment;
use App\Models\Leave\LeaveType;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

/**
 * Local demo data for employee 539 (Hans Fajardo).
 *
 * After seeding, leftover available credits are 5 VL / 5 SL / 5 EL.
 * VL reserved is 2.00 from the two pending demo days.
 */
class HansFajardoDemoSeedService
{
    public const EMP_ID = 539;

    public const REASON_PREFIX = '[DEMO]';

    public const TARGET_AVAILABLE = '5.00';

    public const VL_DAYS_FILED = '5.00';

    public function __construct(
        private readonly LeaveBalanceService $balanceService,
        private readonly LeaveApprovalPolicy $approvalPolicy,
        private readonly LeaveFinalizationService $finalizationService,
        private readonly LeaveDurationService $durationService,
        private readonly LeaveOverlapValidator $overlapValidator,
    ) {}

    /**
     * @return array{
     *     emp_id: int,
     *     leaves: int,
     *     vl_available: string,
     *     vl_reserved: string,
     *     sl_available: string,
     *     el_available: string
     * }
     */
    public function seed(Employee $employee, Employee $approver): array
    {
        $empId = (int) $employee->id;
        $types = $this->leaveTypes();

        return DB::transaction(function () use ($employee, $approver, $empId, $types) {
            $this->assertWorkdays();
            $this->removePreviousDemoLeaves($empId);

            $this->ensureAvailable(
                $empId,
                (int) $types['VL']->id,
                bcadd(self::TARGET_AVAILABLE, self::VL_DAYS_FILED, 2),
            );
            $this->ensureAvailable($empId, (int) $types['SL']->id, self::TARGET_AVAILABLE);
            $this->ensureAvailable($empId, (int) $types['EL']->id, self::TARGET_AVAILABLE);

            foreach ($this->scenarios() as $scenario) {
                $this->createVlDay(
                    $employee,
                    $approver,
                    $types['VL'],
                    $scenario['date'],
                    $scenario['reason'],
                    $scenario['confirm'],
                );
            }

            $balances = $this->balanceService->getForEmployee($empId)->keyBy('code');

            return [
                'emp_id' => $empId,
                'leaves' => count($this->scenarios()),
                'vl_available' => (string) $balances->get('VL')['available'],
                'vl_reserved' => (string) $balances->get('VL')['reserved'],
                'sl_available' => (string) $balances->get('SL')['available'],
                'el_available' => (string) $balances->get('EL')['available'],
            ];
        });
    }

    /**
     * @return list<array{date: string, confirm: bool, reason: string}>
     */
    public function scenarios(): array
    {
        return [
            [
                'date' => '2026-08-28',
                'confirm' => true,
                'reason' => self::REASON_PREFIX.' Approved VL — to cancel by admin',
            ],
            [
                'date' => '2026-09-01',
                'confirm' => false,
                'reason' => self::REASON_PREFIX.' Pending VL — to cancel by employee',
            ],
            [
                'date' => '2026-09-02',
                'confirm' => false,
                'reason' => self::REASON_PREFIX.' Pending VL — to reject by approver',
            ],
            [
                'date' => '2026-09-03',
                'confirm' => true,
                'reason' => self::REASON_PREFIX.' Approved VL — convert to SL',
            ],
            [
                'date' => '2026-09-04',
                'confirm' => true,
                'reason' => self::REASON_PREFIX.' Approved VL — convert to EL',
            ],
        ];
    }

    /**
     * @return array{VL: LeaveType, SL: LeaveType, EL: LeaveType}
     */
    private function leaveTypes(): array
    {
        $types = LeaveType::query()
            ->whereIn('code', ['VL', 'SL', 'EL'])
            ->get()
            ->keyBy('code');

        foreach (['VL', 'SL', 'EL'] as $code) {
            if (! $types->has($code)) {
                throw new RuntimeException("Leave type [{$code}] not found. Run LeaveTypeSeeder first.");
            }
        }

        return [
            'VL' => $types->get('VL'),
            'SL' => $types->get('SL'),
            'EL' => $types->get('EL'),
        ];
    }

    private function assertWorkdays(): void
    {
        foreach ($this->scenarios() as $scenario) {
            $date = Carbon::parse($scenario['date'])->startOfDay();

            if (! $this->durationService->isWorkday($date)) {
                throw new RuntimeException(
                    $scenario['date'].' is not a workday (weekend or company holiday). Pick another date.',
                );
            }
        }
    }

    private function ensureAvailable(int $empId, int $leaveTypeId, string $target): void
    {
        $current = $this->balanceService->availableFor($empId, $leaveTypeId);
        $delta = bcsub($target, $current, 2);

        if (bccomp($delta, '0', 2) === 0) {
            return;
        }

        $this->balanceService->applyManualAdjustment(
            $empId,
            $leaveTypeId,
            $delta,
            'Hans Fajardo demo seeder',
        );
    }

    private function removePreviousDemoLeaves(int $empId): void
    {
        $leaves = LeaveInfo::query()
            ->where('emp_id', $empId)
            ->where('leave_reason', 'like', self::REASON_PREFIX.'%')
            ->get();

        if ($leaves->isEmpty()) {
            return;
        }

        foreach ($leaves as $leave) {
            if ($leave->status === LeaveStatus::PendingApproval) {
                $this->balanceService->releaseReservation($leave);
            }
        }

        $ids = $leaves->pluck('id')->all();

        if (Schema::hasTable('leave_conversion_attachment')) {
            $conversionIds = LeaveConversion::query()->whereIn('leave_id', $ids)->pluck('id');
            LeaveConversionAttachment::query()->whereIn('conversion_id', $conversionIds)->delete();
        }

        if (Schema::hasTable('leave_conversion')) {
            LeaveConversion::query()->whereIn('leave_id', $ids)->delete();
        }

        if (Schema::hasTable('leave_info_attachment')) {
            LeaveInfoAttachment::query()->whereIn('leave_id', $ids)->delete();
        }

        $activityIds = LeaveActivityHistory::query()->whereIn('leave_id', $ids)->pluck('id');

        if (Schema::hasTable('leave_activity_recipient') && $activityIds->isNotEmpty()) {
            LeaveActivityRecipient::query()->whereIn('activity_id', $activityIds)->delete();
        }

        LeaveActivityHistory::query()->whereIn('leave_id', $ids)->delete();
        LeaveApprovalHistory::query()->whereIn('leave_id', $ids)->delete();
        LeaveApprovalRequirement::query()->whereIn('leave_id', $ids)->delete();
        LeaveBalanceTransaction::query()->whereIn('leave_id', $ids)->delete();
        LeaveInfo::query()->whereIn('id', $ids)->delete();
    }

    private function createVlDay(
        Employee $employee,
        Employee $approver,
        LeaveType $vl,
        string $date,
        string $reason,
        bool $confirm,
    ): void {
        $this->overlapValidator->assertNoOverlap((int) $employee->id, $date, $date);

        $leave = LeaveInfo::query()->create([
            'emp_id' => $employee->id,
            'filed_by_emp_id' => $employee->id,
            'leave_type_id' => $vl->id,
            'duration' => '1.00',
            'paid_days' => '1.00',
            'lwop_days' => '0.00',
            'day_type' => DayType::Full,
            'start_date' => $date,
            'end_date' => $date,
            'leave_reason' => $reason,
            'status' => LeaveStatus::PendingApproval,
        ]);

        foreach ($this->approvalPolicy->requirementsFor((int) $employee->id, 'VL') as $requirement) {
            LeaveApprovalRequirement::query()->create([
                'leave_id' => $leave->id,
                'display_order' => $requirement['display_order'],
                'allowed_levels' => $requirement['allowed_levels'],
                'required_approver_type' => $requirement['required_approver_type'],
            ]);
        }

        $this->balanceService->reservePaidDays($leave);

        LeaveActivityHistory::query()->create([
            'leave_id' => $leave->id,
            'performed_by_emp_id' => $employee->id,
            'activity_type' => ActivityType::Submitted,
        ]);

        if (! $confirm) {
            return;
        }

        $requirement = $leave->approvalRequirements()->orderBy('display_order')->firstOrFail();

        LeaveApprovalHistory::query()->create([
            'leave_id' => $leave->id,
            'requirement_id' => $requirement->id,
            'approver_emp_id' => $approver->id,
            'approval_level' => $this->historyApprovalLevel($requirement),
            'approver_type' => $this->historyApproverType($requirement),
            'is_approved' => true,
        ]);

        $requirement->update([
            'is_satisfied' => true,
            'satisfied_at' => now(),
        ]);

        $this->finalizationService->finalize($leave, $approver);
    }

    private function historyApprovalLevel(LeaveApprovalRequirement $requirement): ?int
    {
        if ($requirement->required_approver_type !== null) {
            return null;
        }

        $levels = $requirement->allowed_levels;

        return is_array($levels) && $levels !== [] ? (int) $levels[0] : null;
    }

    private function historyApproverType(LeaveApprovalRequirement $requirement): ApproverType
    {
        return match ($requirement->required_approver_type) {
            RequiredApproverType::President => ApproverType::President,
            RequiredApproverType::Admin => ApproverType::Admin,
            default => ApproverType::Leader,
        };
    }
}
