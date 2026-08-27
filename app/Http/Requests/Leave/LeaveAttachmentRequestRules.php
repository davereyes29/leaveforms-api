<?php

namespace App\Http\Requests\Leave;

use App\Services\Leave\LeaveTypeRules;

/**
 * Shared attachment validation.
 *
 * VL does not allow attachments — use prohibitedForVlRules().
 * SL, PTB, and EL require a file — use requiredAttachmentRules() on submit
 * and optionalAttachmentReplaceRules() on edit.
 */
trait LeaveAttachmentRequestRules
{
    /**
     * @return array<string, mixed>
     */
    protected function attachmentRules(): array
    {
        return [
            'leave_attachment' => ['nullable', 'file', ...$this->attachmentFileConstraints()],
            'remove_attachment' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function requiredAttachmentRules(): array
    {
        return [
            'leave_attachment' => ['required', 'file', ...$this->attachmentFileConstraints()],
            'remove_attachment' => ['prohibited'],
        ];
    }

    /**
     * Replace the attachment on a pending SL, PTB, or EL. Removing without a replacement is not allowed.
     *
     * @return array<string, mixed>
     */
    protected function optionalAttachmentReplaceRules(): array
    {
        return [
            'leave_attachment' => ['sometimes', 'file', ...$this->attachmentFileConstraints()],
            'remove_attachment' => ['prohibited'],
        ];
    }

    /**
     * Block attachment fields on VL endpoints.
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
            'leave_attachment.prohibited' => 'Vacation Leave does not accept attachments. Attachments are only used for other leave types (SL, EL, ML, PL, PTB).',
            'remove_attachment.prohibited' => 'Vacation Leave does not use attachments.',
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function prohibitedAttachmentMessages(string $code): array
    {
        if (strtoupper($code) === 'LL') {
            return [
                'leave_attachment.prohibited' => 'Long Leave does not accept attachments.',
                'remove_attachment.prohibited' => 'Long Leave does not use attachments.',
            ];
        }

        return $this->prohibitedForVlMessages();
    }

    /**
     * @return array<string, string>
     */
    protected function requiredAttachmentMessages(string $code): array
    {
        if (! LeaveTypeRules::requiresAttachment($code)) {
            return [];
        }

        if (LeaveTypeRules::requiresMedCert($code)) {
            return [
                'leave_attachment.required' => 'A medical certificate is required to file this leave type.',
                'remove_attachment.prohibited' => 'This leave type requires a medical certificate. Upload a replacement instead of removing it.',
            ];
        }

        return [
            'leave_attachment.required' => 'A supporting document is required to file this leave type.',
            'remove_attachment.prohibited' => 'This leave type requires a supporting document. Upload a replacement instead of removing it.',
        ];
    }

    /**
     * @return list<string>
     */
    private function attachmentFileConstraints(): array
    {
        $mimes = implode(',', config('leave.attachments.mimes', ['pdf', 'jpg', 'jpeg', 'png']));
        $maxKb = (int) config('leave.attachments.max_kb', 5120);

        return ["mimes:{$mimes}", "max:{$maxKb}"];
    }
}
