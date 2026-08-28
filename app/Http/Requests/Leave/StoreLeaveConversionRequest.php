<?php

namespace App\Http\Requests\Leave;

use App\Services\Leave\LeaveAttachmentService;
use Illuminate\Foundation\Http\FormRequest;

class StoreLeaveConversionRequest extends FormRequest
{
    use LeaveAttachmentRequestRules;

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->wrapUploadedFileArray('med_cert');
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $maxFiles = (int) config('leave.attachments.max_files', 5);

        return [
            'med_cert' => ['required', 'array', 'min:1', 'max:'.$maxFiles],
            'med_cert.*' => ['file', ...$this->attachmentFileConstraints()],
            'reason' => ['required', 'string', 'max:2000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            ...$this->fileArrayMessages('med_cert', true),
            'med_cert.required' => 'A medical certificate attachment is required to request VL → SL conversion.',
            'reason.required' => 'A conversion reason is required so admin can review the request without opening the medical certificate.',
        ];
    }

    /**
     * @return list<UploadedFile>
     */
    public function uploadedMedCerts(): array
    {
        return LeaveAttachmentService::uploadedFiles($this->file('med_cert'));
    }
}
