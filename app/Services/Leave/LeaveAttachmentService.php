<?php

namespace App\Services\Leave;

use App\Enums\Leave\LeaveStatus;
use App\Models\Kdtph\Employee;
use App\Models\Leave\LeaveInfo;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Store and serve leave attachment files.
 */
class LeaveAttachmentService
{
    public function __construct(
        private readonly LeaveApproverResolver $approverResolver,
    ) {}

    public function store(LeaveInfo $leave, UploadedFile $file): string
    {
        $extension = $file->getClientOriginalExtension();
        $filename = Str::uuid()->toString().($extension !== '' ? '.'.$extension : '');

        return $file->storeAs(
            "leave-attachments/{$leave->id}",
            $filename,
            $this->disk(),
        );
    }

    public function replace(LeaveInfo $leave, UploadedFile $file): void
    {
        $this->delete($leave->leave_attachment);

        $leave->update([
            'leave_attachment' => $this->store($leave, $file),
        ]);
    }

    public function remove(LeaveInfo $leave): void
    {
        $this->delete($leave->leave_attachment);

        $leave->update(['leave_attachment' => null]);
    }

    public function delete(?string $path): void
    {
        if ($path === null || $path === '') {
            return;
        }

        if (Storage::disk($this->disk())->exists($path)) {
            Storage::disk($this->disk())->delete($path);
        }
    }

    public function canAccess(LeaveInfo $leave, Employee $viewer): bool
    {
        if ($leave->leave_attachment === null) {
            return false;
        }

        $viewerId = (int) $viewer->id;

        if ($viewerId === (int) $leave->emp_id || $viewerId === (int) $leave->filed_by_emp_id) {
            return true;
        }

        if (! in_array($leave->status, [LeaveStatus::PendingApproval, LeaveStatus::PartiallyApproved], true)) {
            return false;
        }

        $leave->loadMissing('approvalRequirements.history');

        $context = $this->approverResolver->contextFor($viewer->id);
        $filerGroup = $this->approverResolver->filerGroupAbbreviation($leave->emp_id);

        foreach ($leave->approvalRequirements as $requirement) {
            if ($this->approverResolver->canActOnRequirement($context, $requirement, $filerGroup)) {
                return true;
            }
        }

        return false;
    }

    public function disk(): string
    {
        return (string) config('leave.attachments.disk', 'local');
    }
}
