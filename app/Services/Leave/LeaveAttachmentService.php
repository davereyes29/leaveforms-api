<?php

namespace App\Services\Leave;

use App\Enums\Leave\ActivityType;
use App\Enums\Leave\ConversionStatus;
use App\Enums\Leave\LeaveStatus;
use App\Models\Kdtph\Employee;
use App\Models\Leave\LeaveActivityHistory;
use App\Models\Leave\LeaveConversion;
use App\Models\Leave\LeaveConversionAttachment;
use App\Models\Leave\LeaveInfo;
use App\Models\Leave\LeaveInfoAttachment;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Store, add, remove, and serve leave / conversion attachment files.
 */
class LeaveAttachmentService
{
    public function __construct(
        private readonly LeaveListService $listService,
    ) {}

    /**
     * @return list<UploadedFile>
     */
    public static function uploadedFiles(mixed $value): array
    {
        if ($value instanceof UploadedFile) {
            return [$value];
        }

        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter(
            $value,
            fn ($file) => $file instanceof UploadedFile,
        ));
    }

    /**
     * @param  list<UploadedFile>  $files
     */
    public function storeManyForLeave(LeaveInfo $leave, array $files): void
    {
        $sort = (int) $leave->attachments()->max('sort_order');

        foreach ($files as $file) {
            $sort++;
            $this->storeOneForLeave($leave, $file, $sort);
        }
    }

    /**
     * @param  list<UploadedFile>  $files
     */
    public function addToLeave(LeaveInfo $leave, Employee $actor, array $files): LeaveInfo
    {
        $this->assertActorCanModifyLeave($leave, $actor);
        $this->assertLeaveAttachmentsEditable($leave);
        $this->assertLeaveAcceptsAttachments($leave);
        $this->assertCanAdd($leave->attachments()->count(), count($files), 'leave_attachment');

        $leave = DB::transaction(function () use ($leave, $actor, $files) {
            $leave = LeaveInfo::query()->whereKey($leave->id)->lockForUpdate()->firstOrFail();

            $this->assertActorCanModifyLeave($leave, $actor);
            $this->assertLeaveAttachmentsEditable($leave);
            $this->assertCanAdd($leave->attachments()->count(), count($files), 'leave_attachment');

            $this->storeManyForLeave($leave, $files);

            LeaveActivityHistory::query()->create([
                'leave_id' => $leave->id,
                'performed_by_emp_id' => $actor->id,
                'activity_type' => ActivityType::AttachmentAdded,
            ]);

            return $leave->fresh(LeaveListService::DETAIL_RELATIONS);
        });

        return $leave;
    }

    public function removeFromLeave(LeaveInfo $leave, LeaveInfoAttachment $attachment, Employee $actor): LeaveInfo
    {
        $this->assertAttachmentBelongsToLeave($leave, $attachment);
        $this->assertActorCanModifyLeave($leave, $actor);
        $this->assertLeaveAttachmentsEditable($leave);
        $this->assertLeaveAcceptsAttachments($leave);
        $this->assertCanRemove($leave->attachments()->count(), 'leave_attachment', $this->requiredFileLabel($leave));

        $leave = DB::transaction(function () use ($leave, $attachment, $actor) {
            $leave = LeaveInfo::query()->whereKey($leave->id)->lockForUpdate()->firstOrFail();
            $attachment = LeaveInfoAttachment::query()
                ->whereKey($attachment->id)
                ->where('leave_id', $leave->id)
                ->lockForUpdate()
                ->firstOrFail();

            $this->assertActorCanModifyLeave($leave, $actor);
            $this->assertLeaveAttachmentsEditable($leave);
            $this->assertCanRemove($leave->attachments()->count(), 'leave_attachment', $this->requiredFileLabel($leave));

            $this->delete($attachment->path);
            $attachment->delete();

            LeaveActivityHistory::query()->create([
                'leave_id' => $leave->id,
                'performed_by_emp_id' => $actor->id,
                'activity_type' => ActivityType::Modified,
            ]);

            return $leave->fresh(LeaveListService::DETAIL_RELATIONS);
        });

        return $leave;
    }

    /**
     * @param  list<UploadedFile>  $files
     */
    public function storeManyForConversion(LeaveConversion $conversion, array $files): void
    {
        $sort = (int) $conversion->attachments()->max('sort_order');

        foreach ($files as $file) {
            $sort++;
            $this->storeOneForConversion($conversion, $file, $sort);
        }
    }

    /**
     * @param  list<UploadedFile>  $files
     */
    public function replaceConversionAttachments(LeaveConversion $conversion, array $files): void
    {
        $conversion->loadMissing('attachments');

        foreach ($conversion->attachments as $existing) {
            $this->delete($existing->path);
            $existing->delete();
        }

        $this->storeManyForConversion($conversion, $files);
    }

    /**
     * @param  list<UploadedFile>  $files
     */
    public function addToConversion(LeaveConversion $conversion, Employee $actor, array $files): LeaveConversion
    {
        $this->assertActorOwnsConversionLeave($conversion, $actor);
        $this->assertConversionAttachmentsEditable($conversion);
        $this->assertCanAdd($conversion->attachments()->count(), count($files), 'med_cert');

        return DB::transaction(function () use ($conversion, $actor, $files) {
            $conversion = LeaveConversion::query()
                ->whereKey($conversion->id)
                ->lockForUpdate()
                ->with('leaveInfo')
                ->firstOrFail();

            $this->assertActorOwnsConversionLeave($conversion, $actor);
            $this->assertConversionAttachmentsEditable($conversion);
            $this->assertCanAdd($conversion->attachments()->count(), count($files), 'med_cert');

            $this->storeManyForConversion($conversion, $files);

            LeaveActivityHistory::query()->create([
                'leave_id' => $conversion->leave_id,
                'performed_by_emp_id' => $actor->id,
                'activity_type' => ActivityType::AttachmentAdded,
            ]);

            return $conversion->fresh([
                'fromLeaveType',
                'toLeaveType',
                'leaveInfo.leaveType',
                'attachments',
            ]);
        });
    }

    public function removeFromConversion(
        LeaveConversion $conversion,
        LeaveConversionAttachment $attachment,
        Employee $actor,
    ): LeaveConversion {
        $this->assertAttachmentBelongsToConversion($conversion, $attachment);
        $this->assertActorOwnsConversionLeave($conversion, $actor);
        $this->assertConversionAttachmentsEditable($conversion);
        $this->assertCanRemove($conversion->attachments()->count(), 'med_cert', 'medical certificate');

        return DB::transaction(function () use ($conversion, $attachment, $actor) {
            $conversion = LeaveConversion::query()
                ->whereKey($conversion->id)
                ->lockForUpdate()
                ->with('leaveInfo')
                ->firstOrFail();
            $attachment = LeaveConversionAttachment::query()
                ->whereKey($attachment->id)
                ->where('conversion_id', $conversion->id)
                ->lockForUpdate()
                ->firstOrFail();

            $this->assertActorOwnsConversionLeave($conversion, $actor);
            $this->assertConversionAttachmentsEditable($conversion);
            $this->assertCanRemove($conversion->attachments()->count(), 'med_cert', 'medical certificate');

            $this->delete($attachment->path);
            $attachment->delete();

            LeaveActivityHistory::query()->create([
                'leave_id' => $conversion->leave_id,
                'performed_by_emp_id' => $actor->id,
                'activity_type' => ActivityType::Modified,
            ]);

            return $conversion->fresh([
                'fromLeaveType',
                'toLeaveType',
                'leaveInfo.leaveType',
                'attachments',
            ]);
        });
    }

    public function downloadLeaveAttachment(
        LeaveInfo $leave,
        LeaveInfoAttachment $attachment,
        Employee $viewer,
    ): StreamedResponse {
        $this->assertAttachmentBelongsToLeave($leave, $attachment);

        if (! $this->canAccessLeave($leave, $viewer)) {
            throw new AuthorizationException('You are not allowed to access this attachment.');
        }

        return $this->download($attachment->path, $attachment->original_name);
    }

    public function downloadConversionAttachment(
        LeaveConversion $conversion,
        LeaveConversionAttachment $attachment,
        Employee $viewer,
    ): StreamedResponse {
        $this->assertAttachmentBelongsToConversion($conversion, $attachment);

        if (! $this->canAccessConversion($conversion, $viewer)) {
            throw new AuthorizationException('You are not allowed to access this medical certificate.');
        }

        return $this->download($attachment->path, $attachment->original_name);
    }

    public function canAccessLeave(LeaveInfo $leave, Employee $viewer): bool
    {
        return $this->listService->canView($leave, $viewer);
    }

    public function canAccessConversion(LeaveConversion $conversion, Employee $viewer): bool
    {
        $leave = $conversion->leaveInfo;

        if ($leave === null) {
            $conversion->loadMissing('leaveInfo');
            $leave = $conversion->leaveInfo;
        }

        if ($leave === null) {
            return false;
        }

        return $this->listService->canView($leave, $viewer);
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

    public function disk(): string
    {
        return (string) config('leave.attachments.disk', 'local');
    }

    private function storeOneForLeave(LeaveInfo $leave, UploadedFile $file, int $sortOrder): LeaveInfoAttachment
    {
        $path = $this->storeFile($file, "leave-attachments/{$leave->id}");

        return LeaveInfoAttachment::query()->create([
            'leave_id' => $leave->id,
            'path' => $path,
            'original_name' => $this->originalName($file),
            'sort_order' => $sortOrder,
        ]);
    }

    private function storeOneForConversion(
        LeaveConversion $conversion,
        UploadedFile $file,
        int $sortOrder,
    ): LeaveConversionAttachment {
        $path = $this->storeFile($file, "leave-conversions/{$conversion->leave_id}");

        return LeaveConversionAttachment::query()->create([
            'conversion_id' => $conversion->id,
            'path' => $path,
            'original_name' => $this->originalName($file),
            'sort_order' => $sortOrder,
        ]);
    }

    private function storeFile(UploadedFile $file, string $directory): string
    {
        $extension = $file->getClientOriginalExtension();
        $filename = Str::uuid()->toString().($extension !== '' ? '.'.$extension : '');

        return $file->storeAs($directory, $filename, $this->disk());
    }

    private function originalName(UploadedFile $file): string
    {
        $name = basename(str_replace(["\0", '/', '\\'], '', $file->getClientOriginalName()));

        if ($name === '') {
            $name = 'attachment';
        }

        return Str::limit($name, 255, '');
    }

    private function download(string $path, string $originalName): StreamedResponse
    {
        $disk = $this->disk();

        if (! Storage::disk($disk)->exists($path)) {
            abort(404, 'Attachment not found.');
        }

        $as = $this->downloadAs($originalName, $path);

        return Storage::disk($disk)->download($path, $as);
    }

    private function downloadAs(string $originalName, string $path): string
    {
        $name = basename(str_replace(["\0", '/', '\\'], '', $originalName));

        return $name !== '' ? $name : basename($path);
    }

    private function assertCanAdd(int $existingCount, int $incomingCount, string $field): void
    {
        $max = (int) config('leave.attachments.max_files', 5);

        if ($incomingCount < 1) {
            throw ValidationException::withMessages([
                $field => ['Upload at least one file.'],
            ]);
        }

        if ($existingCount + $incomingCount > $max) {
            throw ValidationException::withMessages([
                $field => ["You can attach at most {$max} files."],
            ]);
        }
    }

    private function assertCanRemove(int $existingCount, string $field, string $label): void
    {
        $min = (int) config('leave.attachments.min_files', 1);

        if ($existingCount <= $min) {
            throw ValidationException::withMessages([
                $field => ["This request requires at least one {$label}. Upload a replacement before removing the last file."],
            ]);
        }
    }

    private function assertLeaveAcceptsAttachments(LeaveInfo $leave): void
    {
        $leave->loadMissing('leaveType');
        $code = strtoupper((string) ($leave->leaveType?->code ?? ''));

        if (! LeaveTypeRules::requiresAttachment($code)) {
            throw ValidationException::withMessages([
                'leave_attachment' => ['This leave type does not accept attachments.'],
            ]);
        }
    }

    private function requiredFileLabel(LeaveInfo $leave): string
    {
        $leave->loadMissing('leaveType');
        $code = strtoupper((string) ($leave->leaveType?->code ?? ''));

        return LeaveTypeRules::requiresMedCert($code) ? 'medical certificate' : 'supporting document';
    }

    private function assertActorCanModifyLeave(LeaveInfo $leave, Employee $actor): void
    {
        $isEmployeeOnLeave = (int) $leave->emp_id === (int) $actor->id;
        $isFiler = (int) $leave->filed_by_emp_id === (int) $actor->id;

        if (! $isEmployeeOnLeave && ! $isFiler) {
            throw new AuthorizationException('You are not allowed to modify this leave request.');
        }
    }

    private function assertLeaveAttachmentsEditable(LeaveInfo $leave): void
    {
        if ($leave->status !== LeaveStatus::PendingApproval) {
            throw ValidationException::withMessages([
                'leave' => [
                    'Attachments can only be changed while the leave is pending approval with no approvals yet.',
                ],
            ]);
        }

        if ($leave->approvalRequirements()->where('is_satisfied', true)->exists()) {
            throw ValidationException::withMessages([
                'leave' => [
                    'Attachments can no longer be changed because an approver has already acted.',
                ],
            ]);
        }
    }

    private function assertActorOwnsConversionLeave(LeaveConversion $conversion, Employee $actor): void
    {
        $leave = $conversion->leaveInfo ?? $conversion->leaveInfo()->first();

        if ($leave === null || (int) $leave->emp_id !== (int) $actor->id) {
            throw new AuthorizationException('You can only change medical certificates on your own conversion request.');
        }
    }

    private function assertConversionAttachmentsEditable(LeaveConversion $conversion): void
    {
        if ($conversion->status !== ConversionStatus::PendingReview) {
            throw ValidationException::withMessages([
                'conversion' => ['Medical certificates can only be changed while the conversion is pending review.'],
            ]);
        }
    }

    private function assertAttachmentBelongsToLeave(LeaveInfo $leave, LeaveInfoAttachment $attachment): void
    {
        if ((int) $attachment->leave_id !== (int) $leave->id) {
            abort(404, 'Attachment not found.');
        }
    }

    private function assertAttachmentBelongsToConversion(
        LeaveConversion $conversion,
        LeaveConversionAttachment $attachment,
    ): void {
        if ((int) $attachment->conversion_id !== (int) $conversion->id) {
            abort(404, 'Medical certificate not found.');
        }
    }
}
