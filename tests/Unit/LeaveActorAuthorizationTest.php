<?php

namespace Tests\Unit;

use App\Models\Kdtph\Employee;
use App\Models\Leave\LeaveInfo;
use App\Services\Leave\LeaveActorAuthorization;
use Tests\TestCase;

class LeaveActorAuthorizationTest extends TestCase
{
    public function test_self_file_may_be_modified_by_the_employee(): void
    {
        $leave = $this->leave(empId: 8, filedByEmpId: 8);
        $employee = $this->employee(8);

        $this->assertTrue(LeaveActorAuthorization::mayModify($leave, $employee));
    }

    public function test_on_behalf_may_be_modified_by_the_member_or_filer(): void
    {
        $leave = $this->leave(empId: 8, filedByEmpId: 12);
        $member = $this->employee(8);
        $filer = $this->employee(12);
        $other = $this->employee(99);

        $this->assertTrue(LeaveActorAuthorization::mayModify($leave, $filer));
        $this->assertTrue(LeaveActorAuthorization::mayModify($leave, $member));
        $this->assertFalse(LeaveActorAuthorization::mayModify($leave, $other));
    }

    private function leave(int $empId, int $filedByEmpId): LeaveInfo
    {
        $leave = new LeaveInfo;
        $leave->emp_id = $empId;
        $leave->filed_by_emp_id = $filedByEmpId;

        return $leave;
    }

    private function employee(int $id): Employee
    {
        $employee = new Employee;
        $employee->id = $id;

        return $employee;
    }
}
