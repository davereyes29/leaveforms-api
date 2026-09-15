<?php

namespace Tests\Unit;

use App\Http\Requests\Leave\IndexPendingApprovalsRequest;
use Tests\TestCase;

class IndexPendingApprovalsRequestTest extends TestCase
{
    public function test_q_is_optional_name_or_employee_number_search(): void
    {
        $rules = (new IndexPendingApprovalsRequest)->rules();

        $this->assertSame(['sometimes', 'nullable', 'string', 'max:100'], $rules['q']);
    }
}
