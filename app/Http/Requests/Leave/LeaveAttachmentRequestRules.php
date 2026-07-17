<?php

namespace App\Http\Requests\Leave;

/**
 * Shared attachment validation.
 *
 * VL does not allow attachments — use prohibitedForVlRules().
 * Other leave types (EL, ML, PL, PTB, LL) will use attachmentRules() later.
 */
trait LeaveAttachmentRequestRules
{
    /**
     * @return array<string, mixed>
     */
    protected function attachmentRules(): array
    {
        $mimes = implode(',', config('leave.attachments.mimes', ['pdf', 'jpg', 'jpeg', 'png']));
        $maxKb = (int) config('leave.attachments.max_kb', 5120);

        return [
            'leave_attachment' => ['nullable', 'file', "mimes:{$mimes}", "max:{$maxKb}"],
            'remove_attachment' => ['sometimes', 'boolean'],
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
            'leave_attachment.prohibited' => 'Vacation Leave does not accept attachments. Attachments are only used for other leave types (EL, ML, PL, PTB, LL).',
            'remove_attachment.prohibited' => 'Vacation Leave does not use attachments.',
        ];
    }
}
