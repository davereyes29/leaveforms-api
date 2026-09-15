<?php

namespace Tests\Unit;

use App\Http\Requests\Leave\IndexApprovalsRequest;
use Tests\TestCase;

class IndexApprovalsRequestTest extends TestCase
{
    public function test_q_is_optional_name_or_employee_number_search(): void
    {
        $rules = (new IndexApprovalsRequest)->rules();

        $this->assertContains('sometimes', $rules['q']);
        $this->assertContains('nullable', $rules['q']);
        $this->assertContains('string', $rules['q']);
        $this->assertContains('max:100', $rules['q']);
    }

    public function test_group_id_is_optional_pic_group_filter(): void
    {
        $rules = (new IndexApprovalsRequest)->rules();

        $this->assertContains('sometimes', $rules['group_id']);
        $this->assertContains('nullable', $rules['group_id']);
        $this->assertContains('integer', $rules['group_id']);
        $this->assertContains('min:1', $rules['group_id']);
    }
}
