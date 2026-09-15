<?php

namespace Tests\Unit;

use App\Http\Requests\Leave\IndexAdminLeavesRequest;
use Tests\TestCase;

class IndexAdminLeavesRequestTest extends TestCase
{
    public function test_q_is_optional_name_or_employee_number_search(): void
    {
        $rules = (new IndexAdminLeavesRequest)->rules();

        $this->assertContains('sometimes', $rules['q']);
        $this->assertContains('nullable', $rules['q']);
        $this->assertContains('string', $rules['q']);
        $this->assertContains('max:100', $rules['q']);
    }
}
