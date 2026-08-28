<?php

namespace App\Http\Requests\Leave;

use App\Services\Leave\LeaveTypeRules;
use Illuminate\Http\UploadedFile;

/**
 * Shared attachment validation.
 *
 * VL, LL, ML, and PL do not allow attachments — use prohibitedForVlRules().
 * SL, PTB, and EL require 1–5 files on submit — use requiredAttachmentRules().
 * Edits add/remove files through dedicated endpoints, not the leave update body.
 */
trait LeaveAttachmentRequestRules
{
    protected function wrapUploadedFileArray(string $field): void
    {
        $file = $this->file($field);

        if ($file instanceof UploadedFile) {
            $this->files->set($field, [$file]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    protected function requiredAttachmentRules(string $field = 'leave_attachment'): array
    {
        $maxFiles = $this->maxAttachmentFiles();

        return [
            $field => ['required', 'array', 'min:1', 'max:'.$maxFiles],
            $field.'.*' => ['file', ...$this->attachmentFileConstraints()],
            'remove_attachment' => ['prohibited'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function addAttachmentRules(string $field = 'leave_attachment'): array
    {
        $maxFiles = $this->maxAttachmentFiles();

        return [
            $field => ['required', 'array', 'min:1', 'max:'.$maxFiles],
            $field.'.*' => ['file', ...$this->attachmentFileConstraints()],
        ];
    }

    /**
     * Block attachment fields on VL / LL / ML / PL endpoints, and on leave update
     * (files are added or removed through dedicated attachment routes).
     *
     * @return array<string, mixed>
     */
    protected function prohibitedForVlRules(): array
    {
        return [
            'leave_attachment' => [
                'prohibited',
            ],
            'remove_attachment' => [
                'prohibited',
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function prohibitedForVlMessages(): array
    {
        return [
            'leave_attachment.prohibited' => 'Vacation Leave does not accept attachments. Attachments are only used for other leave types (SL, EL, PTB).',
            'remove_attachment.prohibited' => 'Vacation Leave does not use attachments.',
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function prohibitedAttachmentMessages(string $code): array
    {
        if (in_array(strtoupper($code), ['LL', 'ML', 'PL'], true)) {
            $label = match (strtoupper($code)) {
                'ML' => 'Maternity Leave',
                'PL' => 'Paternity Leave',
                default => 'Long Leave',
            };

            return [
                'leave_attachment.prohibited' => $label.' does not accept attachments.',
                'remove_attachment.prohibited' => $label.' does not use attachments.',
            ];
        }

        return $this->prohibitedForVlMessages();
    }

    /**
     * @return array<string, string>
     */
    protected function prohibitedOnUpdateMessages(string $code): array
    {
        if (LeaveTypeRules::requiresAttachment($code)) {
            return [
                'leave_attachment.prohibited' => 'Add or remove files with the leave attachment endpoints. This request only updates dates and reason.',
                'remove_attachment.prohibited' => 'Remove files with DELETE /leaves/{id}/attachments/{id}.',
            ];
        }

        return $this->prohibitedAttachmentMessages($code);
    }

    /**
     * @return array<string, string>
     */
    protected function requiredAttachmentMessages(string $code, string $field = 'leave_attachment'): array
    {
        if ($field === 'med_cert') {
            return $this->fileArrayMessages($field, true);
        }

        if (! LeaveTypeRules::requiresAttachment($code)) {
            return [];
        }

        return $this->fileArrayMessages($field, LeaveTypeRules::requiresMedCert($code));
    }

    /**
     * @return array<string, string>
     */
    protected function fileArrayMessages(string $field, bool $isMedCert): array
    {
        $maxFiles = $this->maxAttachmentFiles();
        $maxMb = (int) ceil($this->maxAttachmentKb() / 1024);
        $singular = $isMedCert ? 'medical certificate' : 'supporting document';
        $plural = $isMedCert ? 'medical certificates' : 'supporting documents';

        return [
            "{$field}.required" => 'A '.$singular.' is required to file this request.',
            "{$field}.min" => 'Upload at least one '.$singular.'.',
            "{$field}.max" => 'You can attach at most '.$maxFiles.' '.$plural.'.',
            "{$field}.*.file" => 'Each attachment must be a file.',
            "{$field}.*.mimes" => 'Each file must be a PDF, JPG, or PNG.',
            "{$field}.*.max" => 'Each file may be at most '.$maxMb.' MB.',
            'remove_attachment.prohibited' => 'This leave type requires a '.$singular.'. Remove individual files through the attachment endpoint instead.',
        ];
    }

    /**
     * @return list<string>
     */
    protected function attachmentFileConstraints(): array
    {
        $mimes = implode(',', config('leave.attachments.mimes', ['pdf', 'jpg', 'jpeg', 'png']));
        $maxKb = $this->maxAttachmentKb();

        return ["mimes:{$mimes}", "max:{$maxKb}"];
    }

    private function maxAttachmentFiles(): int
    {
        return (int) config('leave.attachments.max_files', 5);
    }

    private function maxAttachmentKb(): int
    {
        return (int) config('leave.attachments.max_kb', 10240);
    }
}
