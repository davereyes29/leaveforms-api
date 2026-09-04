<?php

namespace Tests\Unit;

use App\Http\Requests\Leave\IndexEmployeesWithLwopRequest;
use Tests\TestCase;

class IndexEmployeesWithLwopRequestTest extends TestCase
{
    public function test_year_and_optional_group_are_validated(): void
    {
        $rules = (new IndexEmployeesWithLwopRequest)->rules();

        $this->assertContains('required', $rules['year']);
        $this->assertContains('integer', $rules['year']);
        $this->assertContains('sometimes', $rules['group_id']);
        $this->assertContains('nullable', $rules['group_id']);
        $this->assertArrayNotHasKey('q', $rules);
        $this->assertArrayNotHasKey('emp_ids', $rules);
    }
}
