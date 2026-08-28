<?php

namespace App\Http\Requests\Leave;

use App\Services\Leave\LeaveAttachmentService;
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
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return $this->addAttachmentRules('med_cert');
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return $this->fileArrayMessages('med_cert', true);
    }

    /**
     * @return list<UploadedFile>
     */
    public function uploadedMedCerts(): array
    {
        return LeaveAttachmentService::uploadedFiles($this->file('med_cert'));
    }
}
