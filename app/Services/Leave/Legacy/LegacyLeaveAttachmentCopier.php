<?php

namespace App\Services\Leave\Legacy;

use App\Models\Leave\LeaveInfo;
use App\Models\Leave\LeaveInfoAttachment;
use App\Services\Leave\LeaveAttachmentService;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Copies V1 leaveforms Uploads files into revamp leave_info_attachment storage.
 *
 * Legacy layout: {uploads_path}/{emp_id}/{start_date}/{l_attachment}
 */
class LegacyLeaveAttachmentCopier
{
    public function __construct(
        private readonly LeaveAttachmentService $attachmentService,
    ) {}

    public function uploadsRoot(): string
    {
        return rtrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, (string) config('leave.legacy.uploads_path', '')), DIRECTORY_SEPARATOR);
    }

    public function uploadsRootExists(): bool
    {
        $root = $this->uploadsRoot();

        return $root !== '' && is_dir($root);
    }

    public function normalizeFilename(mixed $raw): ?string
    {
        if ($raw === null) {
            return null;
        }

        $name = trim((string) $raw);
        if ($name === '' || in_array(strtolower($name), ['null', 'none', '0', 'n/a'], true)) {
            return null;
        }

        // Reject path segments — V1 stores basename only.
        if (str_contains($name, '/') || str_contains($name, '\\') || str_contains($name, "\0")) {
            return null;
        }

        $base = basename($name);
        if ($base === '' || $base === '.' || $base === '..') {
            return null;
        }

        return $base;
    }

    public function normalizeStartDate(mixed $raw): ?string
    {
        if ($raw === null) {
            return null;
        }

        if ($raw instanceof \DateTimeInterface) {
            return $raw->format('Y-m-d');
        }

        $value = trim((string) $raw);
        if ($value === '' || ! preg_match('/^\d{4}-\d{2}-\d{2}/', $value)) {
            return null;
        }

        return substr($value, 0, 10);
    }

    /**
     * Absolute path on disk, or null if the file is missing / invalid.
     */
    public function resolveSourcePath(int $empId, mixed $startDate, mixed $attachmentName): ?string
    {
        $filename = $this->normalizeFilename($attachmentName);
        $date = $this->normalizeStartDate($startDate);

        if ($filename === null || $date === null || $empId < 1) {
            return null;
        }

        $root = $this->uploadsRoot();
        if ($root === '') {
            return null;
        }

        $path = $root.DIRECTORY_SEPARATOR.$empId.DIRECTORY_SEPARATOR.$date.DIRECTORY_SEPARATOR.$filename;

        return is_file($path) ? $path : null;
    }

    /**
     * Copy one legacy file onto the leave. No-op if filename empty.
     *
     * @return array{status: 'copied'|'missing'|'skipped_no_name'|'skipped_existing', path?: string}
     */
    public function attachToLeave(LeaveInfo $leave, int $empId, mixed $startDate, mixed $attachmentName, bool $dryRun = false): array
    {
        $filename = $this->normalizeFilename($attachmentName);
        if ($filename === null) {
            return ['status' => 'skipped_no_name'];
        }

        if ($leave->attachments()->exists()) {
            return ['status' => 'skipped_existing'];
        }

        $source = $this->resolveSourcePath($empId, $startDate, $filename);
        if ($source === null) {
            return ['status' => 'missing'];
        }

        if ($dryRun) {
            return ['status' => 'copied', 'path' => $source];
        }

        $relative = $this->storeFromAbsolutePath($leave->id, $source, $filename);

        LeaveInfoAttachment::query()->create([
            'leave_id' => $leave->id,
            'path' => $relative,
            'original_name' => $filename,
            'sort_order' => 0,
        ]);

        return ['status' => 'copied', 'path' => $relative];
    }

    public function storeFromAbsolutePath(int $leaveId, string $absolutePath, string $originalName): string
    {
        if (! is_file($absolutePath) || ! is_readable($absolutePath)) {
            throw new RuntimeException("Cannot read legacy attachment [{$absolutePath}]");
        }

        $extension = pathinfo($originalName, PATHINFO_EXTENSION);
        $storedName = Str::uuid()->toString().($extension !== '' ? '.'.$extension : '');
        $directory = "leave-attachments/{$leaveId}";
        $relative = "{$directory}/{$storedName}";

        $stream = fopen($absolutePath, 'rb');
        if ($stream === false) {
            throw new RuntimeException("Cannot open legacy attachment [{$absolutePath}]");
        }

        try {
            $ok = Storage::disk($this->attachmentService->disk())->writeStream($relative, $stream);
            if ($ok === false) {
                throw new RuntimeException("Failed to store legacy attachment for leave {$leaveId}");
            }
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        return $relative;
    }
}
