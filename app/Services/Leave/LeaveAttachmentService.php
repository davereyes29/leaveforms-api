<?php

namespace App\Services\Leave;

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
        private readonly LeaveListService $listService,
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

    public function storeMedCert(LeaveInfo $leave, UploadedFile $file): string
    {
        $extension = $file->getClientOriginalExtension();
        $filename = Str::uuid()->toString().($extension !== '' ? '.'.$extension : '');

        return $file->storeAs(
            "leave-conversions/{$leave->id}",
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

        return $this->listService->canView($leave, $viewer);
    }

    public function disk(): string
    {
        return (string) config('leave.attachments.disk', 'local');
    }
}
