<?php

namespace App\Services\Leave;

use App\Mail\Leave\ConversionApprovedMail;
use App\Mail\Leave\ConversionRejectedMail;
use App\Mail\Leave\ConversionRequestedMail;
use App\Mail\Leave\LeaveCancelledMail;
use App\Mail\Leave\LeaveConfirmedMail;
use App\Mail\Leave\LeaveEditedMail;
use App\Mail\Leave\LeaveRejectedMail;
use App\Mail\Leave\LeaveSubmittedMail;
use App\Mail\Leave\SickLeaveSubmittedMail;
use App\Models\Kdtph\Employee;
use App\Models\Leave\LeaveConversion;
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

        if ($this->isAdminMedCertLeave($leave)) {
            $this->notifyAdminOnMedCertLeaveSubmit($leave);

            return;
        }

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
     * Notify approvers when a pending leave is edited and needs review again.
     */
    public function notifyApproversOnEdit(LeaveInfo $leave): void
    {
        if (! config('leave.notifications.enabled', true)) {
            return;
        }

        $leave->loadMissing(['leaveType', 'approvalRequirements']);

        $employeeOnLeave = Employee::query()->find($leave->emp_id);
        $employeeName = $employeeOnLeave?->fullName() ?? 'An employee';

        $approvers = $this->recipientService->forFirstPendingRequirement($leave);

        if ($approvers->isEmpty()) {
            Log::warning('Leave edit: no approver emails found.', [
                'leave_id' => $leave->id,
                'emp_id' => $leave->emp_id,
            ]);

            return;
        }

        foreach ($approvers as $approver) {
            try {
                Mail::to($approver->email)->send(
                    new LeaveEditedMail(
                        leave: $leave,
                        employeeName: $employeeName,
                        approverName: $approver->firstname ?: 'there',
                    ),
                );
            } catch (\Throwable $exception) {
                Log::error('Leave edit notification failed.', [
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
     * Notify designated approvers when a leave is cancelled by the employee.
     */
    public function notifyApproversOnCancel(LeaveInfo $leave, string $cancellationReason): void
    {
        if (! config('leave.notifications.enabled', true)) {
            return;
        }

        $leave->loadMissing(['leaveType', 'approvalRequirements']);

        if ($this->isAdminMedCertLeave($leave)) {
            $this->notifyAdminOnMedCertLeaveCancel($leave, $cancellationReason);

            return;
        }

        $employeeOnLeave = Employee::query()->find($leave->emp_id);
        $employeeName = $employeeOnLeave?->fullName() ?? 'An employee';

        $approvers = $this->recipientService->forFirstPendingRequirement($leave);

        if ($approvers->isEmpty()) {
            Log::warning('Leave cancel: no approver emails found.', [
                'leave_id' => $leave->id,
                'emp_id' => $leave->emp_id,
            ]);

            return;
        }

        foreach ($approvers as $approver) {
            try {
                Mail::to($approver->email)->send(
                    new LeaveCancelledMail(
                        leave: $leave,
                        employeeName: $employeeName,
                        approverName: $approver->firstname ?: 'there',
                        cancellationReason: $cancellationReason,
                    ),
                );
            } catch (\Throwable $exception) {
                Log::error('Leave cancel notification failed.', [
                    'leave_id' => $leave->id,
                    'approver_emp_id' => $approver->id,
                    'approver_email' => $approver->email,
                    'error' => $exception->getMessage(),
                ]);
            }
        }
    }

    /**
     * Notify admin (To) and designated approvers (CC) when conversion is requested.
     * Med cert is attached on the mailable.
     */
    public function notifyAdminOnConversionRequest(LeaveConversion $conversion): void
    {
        if (! config('leave.notifications.enabled', true)) {
            return;
        }

        $conversion->loadMissing(['leaveInfo.leaveType', 'leaveInfo.approvalRequirements', 'fromLeaveType', 'toLeaveType', 'attachments']);
        $leave = $conversion->leaveInfo;

        if ($leave === null) {
            Log::warning('Conversion request: leave missing.', [
                'conversion_id' => $conversion->id,
            ]);

            return;
        }

        $admin = $this->adminEmployee();

        if ($admin === null) {
            Log::warning('Conversion request: admin email not found.', [
                'conversion_id' => $conversion->id,
                'leave_id' => $leave->id,
                'admin_emp_id' => config('leave.admin_emp_id'),
            ]);

            return;
        }

        $employeeOnLeave = Employee::query()->find($leave->emp_id);
        $employeeName = $employeeOnLeave?->fullName() ?? 'An employee';
        $adminName = $admin->firstname ?: 'there';

        $ccEmails = $this->recipientService
            ->forFirstPendingRequirement($leave)
            ->reject(fn (Employee $approver) => (int) $approver->id === (int) $admin->id)
            ->pluck('email')
            ->filter()
            ->unique()
            ->values()
            ->all();

        try {
            $mail = Mail::to($admin->email);

            if ($ccEmails !== []) {
                $mail->cc($ccEmails);
            }

            $mail->send(
                new ConversionRequestedMail(
                    leave: $leave,
                    conversion: $conversion,
                    employeeName: $employeeName,
                    adminName: $adminName,
                ),
            );
        } catch (\Throwable $exception) {
            Log::error('Conversion request notification failed.', [
                'conversion_id' => $conversion->id,
                'leave_id' => $leave->id,
                'admin_emp_id' => $admin->id,
                'admin_email' => $admin->email,
                'error' => $exception->getMessage(),
            ]);
        }
    }

    /**
     * Notify the employee on leave when conversion is approved (no filer, no approver CC).
     */
    public function notifyEmployeeOnConversionApproved(LeaveConversion $conversion, Employee $admin): void
    {
        if (! config('leave.notifications.enabled', true)) {
            return;
        }

        $conversion->loadMissing('leaveInfo.leaveType');
        $leave = $conversion->leaveInfo;

        if ($leave === null) {
            Log::warning('Conversion approved: leave missing.', [
                'conversion_id' => $conversion->id,
            ]);

            return;
        }

        $employee = $this->employeeRecipient($leave->emp_id);

        if ($employee === null) {
            Log::warning('Conversion approved: no employee email found.', [
                'conversion_id' => $conversion->id,
                'leave_id' => $leave->id,
                'emp_id' => $leave->emp_id,
            ]);

            return;
        }

        $adminName = $admin->fullName() !== ''
            ? $admin->fullName()
            : ($admin->firstname ?: 'Admin');

        try {
            Mail::to($employee->email)->send(
                new ConversionApprovedMail(
                    leave: $leave,
                    conversion: $conversion,
                    employeeName: $employee->fullName() ?: 'An employee',
                    recipientName: $employee->firstname ?: 'there',
                    adminName: $adminName,
                ),
            );
        } catch (\Throwable $exception) {
            Log::error('Conversion approved notification failed.', [
                'conversion_id' => $conversion->id,
                'leave_id' => $leave->id,
                'recipient_emp_id' => $employee->id,
                'recipient_email' => $employee->email,
                'error' => $exception->getMessage(),
            ]);
        }
    }

    /**
     * Notify the employee on leave when conversion is rejected (no filer, no approver CC).
     */
    public function notifyEmployeeOnConversionRejected(
        LeaveConversion $conversion,
        Employee $admin,
        string $remarks,
    ): void {
        if (! config('leave.notifications.enabled', true)) {
            return;
        }

        $conversion->loadMissing('leaveInfo.leaveType');
        $leave = $conversion->leaveInfo;

        if ($leave === null) {
            Log::warning('Conversion rejected: leave missing.', [
                'conversion_id' => $conversion->id,
            ]);

            return;
        }

        $employee = $this->employeeRecipient($leave->emp_id);

        if ($employee === null) {
            Log::warning('Conversion rejected: no employee email found.', [
                'conversion_id' => $conversion->id,
                'leave_id' => $leave->id,
                'emp_id' => $leave->emp_id,
            ]);

            return;
        }

        $adminName = $admin->fullName() !== ''
            ? $admin->fullName()
            : ($admin->firstname ?: 'Admin');

        try {
            Mail::to($employee->email)->send(
                new ConversionRejectedMail(
                    leave: $leave,
                    conversion: $conversion,
                    employeeName: $employee->fullName() ?: 'An employee',
                    recipientName: $employee->firstname ?: 'there',
                    adminName: $adminName,
                    remarks: $remarks,
                ),
            );
        } catch (\Throwable $exception) {
            Log::error('Conversion rejected notification failed.', [
                'conversion_id' => $conversion->id,
                'leave_id' => $leave->id,
                'recipient_emp_id' => $employee->id,
                'recipient_email' => $employee->email,
                'error' => $exception->getMessage(),
            ]);
        }
    }

    /**
     * Notify admin (To) and designated managers (CC) when an admin-approved leave is filed.
     * A supporting file is attached on the mailable when the leave type requires one.
     */
    private function notifyAdminOnMedCertLeaveSubmit(LeaveInfo $leave): void
    {
        $employeeOnLeave = Employee::query()->find($leave->emp_id);
        $employeeName = $employeeOnLeave?->fullName() ?? 'An employee';

        $admin = $this->adminEmployee();

        if ($admin === null) {
            Log::warning('Admin leave submit: admin email not found.', [
                'leave_id' => $leave->id,
                'emp_id' => $leave->emp_id,
                'admin_emp_id' => config('leave.admin_emp_id'),
            ]);

            return;
        }

        $adminName = $admin->firstname ?: 'there';
        $ccEmails = $this->managerCcEmails($leave, $admin);

        try {
            $mail = Mail::to($admin->email);

            if ($ccEmails !== []) {
                $mail->cc($ccEmails);
            }

            $mail->send(
                new SickLeaveSubmittedMail(
                    leave: $leave,
                    employeeName: $employeeName,
                    adminName: $adminName,
                ),
            );
        } catch (\Throwable $exception) {
            Log::error('Admin leave submit notification failed.', [
                'leave_id' => $leave->id,
                'admin_emp_id' => $admin->id,
                'admin_email' => $admin->email,
                'error' => $exception->getMessage(),
            ]);
        }
    }

    /**
     * Notify admin (To) and designated managers (CC) when pending admin-approved leave is cancelled.
     */
    private function notifyAdminOnMedCertLeaveCancel(LeaveInfo $leave, string $cancellationReason): void
    {
        $employeeOnLeave = Employee::query()->find($leave->emp_id);
        $employeeName = $employeeOnLeave?->fullName() ?? 'An employee';

        $admin = $this->adminEmployee();

        if ($admin === null) {
            Log::warning('Admin leave cancel: admin email not found.', [
                'leave_id' => $leave->id,
                'emp_id' => $leave->emp_id,
                'admin_emp_id' => config('leave.admin_emp_id'),
            ]);

            return;
        }

        $ccEmails = $this->managerCcEmails($leave, $admin);

        try {
            $mail = Mail::to($admin->email);

            if ($ccEmails !== []) {
                $mail->cc($ccEmails);
            }

            $mail->send(
                new LeaveCancelledMail(
                    leave: $leave,
                    employeeName: $employeeName,
                    approverName: $admin->firstname ?: 'there',
                    cancellationReason: $cancellationReason,
                ),
            );
        } catch (\Throwable $exception) {
            Log::error('Admin leave cancel notification failed.', [
                'leave_id' => $leave->id,
                'admin_emp_id' => $admin->id,
                'admin_email' => $admin->email,
                'error' => $exception->getMessage(),
            ]);
        }
    }

    /**
     * @return list<string>
     */
    private function managerCcEmails(LeaveInfo $leave, Employee $admin): array
    {
        return $this->recipientService
            ->designatedManagersForEmployee((int) $leave->emp_id)
            ->reject(fn (Employee $approver) => (int) $approver->id === (int) $admin->id)
            ->pluck('email')
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    private function isAdminMedCertLeave(LeaveInfo $leave): bool
    {
        return LeaveTypeRules::isAdminApproved((string) $leave->leaveType?->code);
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

    private function employeeRecipient(int $empId): ?Employee
    {
        if ($empId <= 0) {
            return null;
        }

        return Employee::query()
            ->whereKey($empId)
            ->where('emp_status', 1)
            ->whereNotNull('email')
            ->where('email', '!=', '')
            ->first();
    }

    private function adminEmployee(): ?Employee
    {
        $adminId = (int) config('leave.admin_emp_id');

        if ($adminId <= 0) {
            return null;
        }

        return Employee::query()
            ->whereKey($adminId)
            ->where('emp_status', 1)
            ->whereNotNull('email')
            ->where('email', '!=', '')
            ->first();
    }
}
