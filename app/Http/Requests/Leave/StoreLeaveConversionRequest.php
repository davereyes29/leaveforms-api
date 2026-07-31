<?php

namespace App\Http\Requests\Leave;

use Illuminate\Foundation\Http\FormRequest;

class StoreLeaveConversionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $mimes = implode(',', config('leave.attachments.mimes', ['pdf', 'jpg', 'jpeg', 'png']));
        $maxKb = (int) config('leave.attachments.max_kb', 5120);

        return [
            'med_cert' => ['required', 'file', "mimes:{$mimes}", "max:{$maxKb}"],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'med_cert.required' => 'A medical certificate attachment is required to request VL → SL conversion.',
        ];
    }
}
