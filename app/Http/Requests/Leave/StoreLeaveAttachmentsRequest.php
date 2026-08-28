<?php

namespace App\Http\Requests\Leave;

use App\Models\Leave\LeaveInfo;
use App\Services\Leave\LeaveAttachmentService;
use App\Services\Leave\LeaveTypeRules;
use Illuminate\Foundation\Http\FormRequest;

class StoreLeaveAttachmentsRequest extends FormRequest
{
    use LeaveAttachmentRequestRules;

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->wrapUploadedFileArray('leave_attachment');
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return $this->addAttachmentRules('leave_attachment');
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        $leave = $this->route('leave');
        $code = '';

        if ($leave instanceof LeaveInfo) {
            $leave->loadMissing('leaveType');
            $code = strtoupper((string) ($leave->leaveType?->code ?? ''));
        }

        return $this->fileArrayMessages('leave_attachment', LeaveTypeRules::requiresMedCert($code));
    }

    /**
     * @return list<UploadedFile>
     */
    public function uploadedAttachments(): array
    {
        return LeaveAttachmentService::uploadedFiles($this->file('leave_attachment'));
    }
}
