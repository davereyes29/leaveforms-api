<?php

namespace App\Services\Leave;

use App\Mail\Leave\LeaveConfirmedMail;
use App\Mail\Leave\LeaveRejectedMail;
use App\Mail\Leave\LeaveSubmittedMail;
use App\Models\Kdtph\Employee;
use App\Models\Leave\LeaveInfo;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Sends leave-related emails.
 */
class LeaveNotificationService
{
    public function __construct(
        private readonly LeaveApproverRecipientService $recipientService,
    ) {}

    /**
     * Notify approvers for the first requirement when a leave is submitted.
     */
    public function notifyApproversOnSubmit(LeaveInfo $leave): void
    {
        if (! config('leave.notifications.enabled', true)) {
            return;
        }

        $leave->loadMissing(['leaveType', 'approvalRequirements']);

        $employeeOnLeave = Employee::query()->find($leave->emp_id);
        $employeeName = $employeeOnLeave?->fullName() ?? 'An employee';

        $approvers = $this->recipientService->forFirstPendingRequirement($leave);

        if ($approvers->isEmpty()) {
            Log::warning('Leave submit: no approver emails found.', [
                'leave_id' => $leave->id,
                'emp_id' => $leave->emp_id,
            ]);

            return;
        }

        foreach ($approvers as $approver) {
            try {
                Mail::to($approver->email)->send(
                    new LeaveSubmittedMail(
                        leave: $leave,
                        employeeName: $employeeName,
                        approverName: $approver->firstname,
                    ),
                );
            } catch (\Throwable $exception) {
                Log::error('Leave submit notification failed.', [
                    'leave_id' => $leave->id,
                    'approver_emp_id' => $approver->id,
                    'approver_email' => $approver->email,
                    'error' => $exception->getMessage(),
                ]);
            }
        }
    }

    /**
     * Notify the employee on leave (and filer if on-behalf) when leave is confirmed.
     */
    public function notifyEmployeeOnConfirm(LeaveInfo $leave, Employee $approver): void
    {
        if (! config('leave.notifications.enabled', true)) {
            return;
        }

        $leave->loadMissing('leaveType');

        $employeeOnLeave = Employee::query()->find($leave->emp_id);
        $employeeName = $employeeOnLeave?->fullName() ?? 'An employee';
        $approverName = $approver->fullName() !== ''
            ? $approver->fullName()
            : ($approver->firstname ?: 'An approver');

        $recipients = $this->employeeAndFilerRecipients($leave);

        if ($recipients->isEmpty()) {
            Log::warning('Leave confirm: no recipient emails found.', [
                'leave_id' => $leave->id,
                'emp_id' => $leave->emp_id,
                'filed_by_emp_id' => $leave->filed_by_emp_id,
            ]);

            return;
        }

        foreach ($recipients as $recipient) {
            try {
                Mail::to($recipient->email)->send(
                    new LeaveConfirmedMail(
                        leave: $leave,
                        employeeName: $employeeName,
                        recipientName: $recipient->firstname ?: 'there',
                        approverName: $approverName,
                    ),
                );
            } catch (\Throwable $exception) {
                Log::error('Leave confirm notification failed.', [
                    'leave_id' => $leave->id,
                    'recipient_emp_id' => $recipient->id,
                    'recipient_email' => $recipient->email,
                    'error' => $exception->getMessage(),
                ]);
            }
        }
    }

    /**
     * Notify the employee on leave (and filer if on-behalf) when leave is rejected.
     */
    public function notifyEmployeeOnReject(LeaveInfo $leave, Employee $approver, string $remarks): void
    {
        if (! config('leave.notifications.enabled', true)) {
            return;
        }

        $leave->loadMissing('leaveType');

        $employeeOnLeave = Employee::query()->find($leave->emp_id);
        $employeeName = $employeeOnLeave?->fullName() ?? 'An employee';
        $approverName = $approver->fullName() !== ''
            ? $approver->fullName()
            : ($approver->firstname ?: 'An approver');

        $recipients = $this->employeeAndFilerRecipients($leave);

        if ($recipients->isEmpty()) {
            Log::warning('Leave reject: no recipient emails found.', [
                'leave_id' => $leave->id,
                'emp_id' => $leave->emp_id,
                'filed_by_emp_id' => $leave->filed_by_emp_id,
            ]);

            return;
        }

        foreach ($recipients as $recipient) {
            try {
                Mail::to($recipient->email)->send(
                    new LeaveRejectedMail(
                        leave: $leave,
                        employeeName: $employeeName,
                        recipientName: $recipient->firstname ?: 'there',
                        approverName: $approverName,
                        remarks: $remarks,
                    ),
                );
            } catch (\Throwable $exception) {
                Log::error('Leave reject notification failed.', [
                    'leave_id' => $leave->id,
                    'recipient_emp_id' => $recipient->id,
                    'recipient_email' => $recipient->email,
                    'error' => $exception->getMessage(),
                ]);
            }
        }
    }

    /**
     * Employee on leave always; filer only when different (on-behalf).
     *
     * @return Collection<int, Employee>
     */
    private function employeeAndFilerRecipients(LeaveInfo $leave): Collection
    {
        $ids = collect([(int) $leave->emp_id]);

        if ((int) $leave->filed_by_emp_id !== (int) $leave->emp_id) {
            $ids->push((int) $leave->filed_by_emp_id);
        }

        return Employee::query()
            ->whereIn('id', $ids->unique()->filter(fn (int $id) => $id > 0)->all())
            ->where('emp_status', 1)
            ->whereNotNull('email')
            ->where('email', '!=', '')
            ->get()
            ->unique('id')
            ->values();
    }
}

