<?php

namespace Tests\Unit;

use App\Http\Requests\Leave\IndexMonthlyAttendanceReportRequest;
use Tests\TestCase;

class IndexMonthlyAttendanceReportRequestTest extends TestCase
{
    public function test_emp_ids_are_optional_and_have_no_count_cap(): void
    {
        $rules = (new IndexMonthlyAttendanceReportRequest)->rules();

        $this->assertContains('sometimes', $rules['emp_ids']);
        $this->assertContains('array', $rules['emp_ids']);
        $this->assertContains('min:1', $rules['emp_ids']);

        foreach ($rules['emp_ids'] as $rule) {
            $this->assertFalse(
                is_string($rule) && str_starts_with($rule, 'max:'),
                'emp_ids must not have a max count cap',
            );
        }
    }
}
