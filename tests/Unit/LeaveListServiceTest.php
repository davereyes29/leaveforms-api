<?php

namespace Tests\Unit;

use App\Enums\Leave\LeaveStatus;
use App\Models\Kdtph\Employee;
use App\Models\Leave\LeaveInfo;
use App\Services\Leave\ApproverContext;
use App\Services\Leave\LeaveApproverResolver;
use App\Services\Leave\LeaveListService;
use Mockery;
use Tests\TestCase;

class LeaveListServiceTest extends TestCase
{
    public function test_president_may_view_any_p4_leave(): void
    {
        $resolver = Mockery::mock(LeaveApproverResolver::class);
        $resolver->shouldReceive('contextFor')->with(1)->andReturn($this->context(
            empId: 1,
            isPresident: true,
        ));
        $resolver->shouldReceive('contextFor')->with(20)->andReturn($this->context(
            empId: 20,
            approvalLevel: 4,
            groupCodes: ['SYS'],
        ));

        $service = new LeaveListService($resolver);

        $this->assertTrue($service->canView(
            $this->leave(empId: 20, status: LeaveStatus::Confirmed),
            $this->employee(1),
        ));
    }

    public function test_president_may_not_view_non_p4_leave_without_other_access(): void
    {
        $resolver = Mockery::mock(LeaveApproverResolver::class);
        $resolver->shouldReceive('contextFor')->with(1)->andReturn($this->context(
            empId: 1,
            isPresident: true,
        ));
        $resolver->shouldReceive('contextFor')->with(8)->andReturn($this->context(
            empId: 8,
            approvalLevel: 1,
            groupCodes: ['SYS'],
        ));
        $resolver->shouldReceive('filerGroupAbbreviation')->with(8)->andReturn('SYS');

        $leave = $this->leave(empId: 8, status: LeaveStatus::Confirmed);
        $leave->setRelation('approvalRequirements', collect());

        $service = new LeaveListService($resolver);

        $this->assertFalse($service->canView($leave, $this->employee(1)));
    }

    /**
     * @param  list<string>  $groupCodes
     */
    private function context(
        int $empId,
        ?int $approvalLevel = null,
        array $groupCodes = [],
        bool $isPresident = false,
    ): ApproverContext {
        return new ApproverContext(
            empId: $empId,
            approvalLevel: $approvalLevel,
            groupCodes: $groupCodes,
            isPresident: $isPresident,
        );
    }

    private function leave(int $empId, LeaveStatus $status): LeaveInfo
    {
        $leave = new LeaveInfo;
        $leave->emp_id = $empId;
        $leave->filed_by_emp_id = $empId;
        $leave->status = $status;

        return $leave;
    }

    private function employee(int $id): Employee
    {
        $employee = new Employee;
        $employee->id = $id;

        return $employee;
    }
}
