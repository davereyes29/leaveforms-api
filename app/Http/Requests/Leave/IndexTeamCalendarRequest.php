<?php

namespace App\Http\Requests\Leave;

use App\Models\Kdtph\Group;
use Carbon\Carbon;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Validates query parameters for GET /api/team/calendar.
 */
class IndexTeamCalendarRequest extends FormRequest
{
    private const MAX_RANGE_DAYS = 62;

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('code') && is_string($this->code)) {
            $this->merge([
                'code' => strtoupper($this->code),
            ]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
            'code' => [
                'sometimes',
                'nullable',
                'string',
                Rule::exists('leave_type', 'code'),
            ],
            'group_id' => [
                'sometimes',
                'nullable',
                'integer',
                'min:1',
                Rule::exists(Group::class, 'id'),
            ],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            [$from, $to] = $this->resolvedRange();

            if ($from->diffInDays($to) > self::MAX_RANGE_DAYS) {
                $validator->errors()->add(
                    'to',
                    'The date range may not exceed '.self::MAX_RANGE_DAYS.' days.',
                );
            }
        });
    }

    /**
     * Inclusive start/end dates for the calendar query.
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    public function resolvedRange(): array
    {
        return [
            Carbon::createFromFormat('Y-m-d', $this->input('from'))->startOfDay(),
            Carbon::createFromFormat('Y-m-d', $this->input('to'))->startOfDay(),
        ];
    }
}
