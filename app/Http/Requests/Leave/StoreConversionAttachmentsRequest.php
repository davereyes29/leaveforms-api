<?php

namespace App\Http\Requests\Leave;

use App\Models\Leave\LeaveConversion;
use App\Services\Leave\LeaveAttachmentService;
use App\Services\Leave\LeaveTypeRules;
use Illuminate\Foundation\Http\FormRequest;

class StoreConversionAttachmentsRequest extends FormRequest
{
    use LeaveAttachmentRequestRules;

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->wrapUploadedFileArray('med_cert');
        $this->wrapUploadedFileArray('leave_attachment');
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $field = LeaveTypeRules::conversionAttachmentField($this->toCode());
        $otherField = $field === 'leave_attachment' ? 'med_cert' : 'leave_attachment';

        return [
            ...$this->addAttachmentRules($field),
            $otherField => ['prohibited'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        $toCode = $this->toCode();
        $field = LeaveTypeRules::conversionAttachmentField($toCode);
        $label = LeaveTypeRules::attachmentLabel($toCode) ?? [
            'singular' => 'attachment',
            'plural' => 'attachments',
        ];

        return [
            ...$this->fileArrayMessages($field, $label),
            'med_cert.prohibited' => 'Emergency Leave conversion uses leave_attachment[], not med_cert[].',
            'leave_attachment.prohibited' => 'Sick Leave conversion uses med_cert[], not leave_attachment[].',
        ];
    }

    /**
     * @return list<UploadedFile>
     */
    public function uploadedAttachments(): array
    {
        $field = LeaveTypeRules::conversionAttachmentField($this->toCode());

        return LeaveAttachmentService::uploadedFiles($this->file($field));
    }

    private function toCode(): string
    {
        $conversion = $this->route('conversion');

        if ($conversion instanceof LeaveConversion) {
            $conversion->loadMissing('toLeaveType');

            return strtoupper((string) ($conversion->toLeaveType?->code ?? 'SL'));
        }

        return 'SL';
    }
}
