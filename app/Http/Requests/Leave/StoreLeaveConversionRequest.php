<?php

namespace App\Http\Requests\Leave;

use App\Services\Leave\LeaveAttachmentService;
use App\Services\Leave\LeaveTypeRules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreLeaveConversionRequest extends FormRequest
{
    use LeaveAttachmentRequestRules;

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $toCode = $this->input('to_code');

        $this->merge([
            'to_code' => ($toCode === null || $toCode === '')
                ? 'SL'
                : strtoupper((string) $toCode),
        ]);

        $this->wrapUploadedFileArray('med_cert');
        $this->wrapUploadedFileArray('leave_attachment');
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $toCode = $this->toCode();
        $field = LeaveTypeRules::conversionAttachmentField($toCode);
        $otherField = $field === 'leave_attachment' ? 'med_cert' : 'leave_attachment';
        $maxFiles = (int) config('leave.attachments.max_files', 5);

        return [
            'to_code' => ['required', 'string', Rule::in(LeaveTypeRules::conversionTargetCodes())],
            $field => ['required', 'array', 'min:1', 'max:'.$maxFiles],
            $field.'.*' => ['file', ...$this->attachmentFileConstraints()],
            $otherField => ['prohibited'],
            'reason' => ['required', 'string', 'max:2000'],
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
        $targetName = $toCode === 'EL' ? 'Emergency Leave' : 'Sick Leave';

        return [
            ...$this->fileArrayMessages($field, $label),
            "{$field}.required" => 'A '.$label['singular'].' is required to request VL → '.$toCode.' conversion.',
            'med_cert.prohibited' => 'Emergency Leave conversion uses leave_attachment[], not med_cert[].',
            'leave_attachment.prohibited' => 'Sick Leave conversion uses med_cert[], not leave_attachment[].',
            'reason.required' => 'A conversion reason is required so admin can review the request without opening the '.$targetName.' attachment.',
        ];
    }

    public function toCode(): string
    {
        return strtoupper((string) $this->input('to_code', 'SL'));
    }

    /**
     * @return list<UploadedFile>
     */
    public function uploadedAttachments(): array
    {
        $field = LeaveTypeRules::conversionAttachmentField($this->toCode());

        return LeaveAttachmentService::uploadedFiles($this->file($field));
    }
}
