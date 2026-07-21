<?php

namespace App\Services\Leave;

use App\Mail\Leave\LeaveSubmittedMail;
use App\Models\Kdtph\Employee;
use App\Models\Leave\LeaveInfo;
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
}
