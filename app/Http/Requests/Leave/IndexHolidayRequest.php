<?php

namespace App\Http\Requests\Leave;

use Carbon\Carbon;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Validates query parameters for GET /api/holidays.
 *
 * Either (from + to) or year is required. When both are sent, from/to win.
 */
class IndexHolidayRequest extends FormRequest
{
    private const MAX_RANGE_DAYS = 400;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'from' => ['required_without:year', 'date_format:Y-m-d'],
            'to' => ['required_without:year', 'date_format:Y-m-d', 'after_or_equal:from'],
            'year' => ['required_without_all:from,to', 'integer', 'min:2000', 'max:2100'],
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
     * Inclusive start/end dates for the holiday query.
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    public function resolvedRange(): array
    {
        if ($this->filled('from') && $this->filled('to')) {
            return [
                Carbon::createFromFormat('Y-m-d', $this->input('from'))->startOfDay(),
                Carbon::createFromFormat('Y-m-d', $this->input('to'))->startOfDay(),
            ];
        }

        $year = (int) $this->input('year');

        return [
            Carbon::create($year, 1, 1)->startOfDay(),
            Carbon::create($year, 12, 31)->startOfDay(),
        ];
    }
}
