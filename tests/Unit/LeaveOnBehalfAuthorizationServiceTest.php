<?php

namespace Tests\Unit;

use App\Models\Kdtph\Employee;
use App\Services\Leave\ApproverContext;
use App\Services\Leave\LeaveApproverResolver;
use App\Services\Leave\LeaveOnBehalfAuthorizationService;
use Mockery;
use Tests\TestCase;

class LeaveOnBehalfAuthorizationServiceTest extends TestCase
{
    public function test_president_may_file_for_p4(): void
    {
        $service = $this->service(
            filer: $this->context(empId: 1, isPresident: true),
            target: $this->context(empId: 20, approvalLevel: 4),
        );

        $this->assertTrue($service->canFileFor($this->employee(1), $this->employee(20)));
    }

    public function test_president_may_not_file_for_non_p4(): void
    {
        $service = $this->service(
            filer: $this->context(empId: 1, isPresident: true),
            target: $this->context(empId: 21, approvalLevel: 3),
        );

        $this->assertFalse($service->canFileFor($this->employee(1), $this->employee(21)));
    }

    public function test_p4_still_may_not_file_for_another_p4(): void
    {
        $service = $this->service(
            filer: $this->context(empId: 20, approvalLevel: 4, groupCodes: ['SYS']),
            target: $this->context(empId: 21, approvalLevel: 4),
            targetGroup: 'SYS',
        );

        $this->assertFalse($service->canFileFor($this->employee(20), $this->employee(21)));
    }

    public function test_regular_employee_may_not_file_on_behalf(): void
    {
        $service = $this->service(
            filer: $this->context(empId: 8, approvalLevel: null),
            target: $this->context(empId: 20, approvalLevel: 4),
            targetGroup: 'SYS',
        );

        $this->assertFalse($service->canFileFor($this->employee(8), $this->employee(20)));
    }

    private function service(
        ApproverContext $filer,
        ApproverContext $target,
        ?string $targetGroup = null,
    ): LeaveOnBehalfAuthorizationService {
        $resolver = Mockery::mock(LeaveApproverResolver::class);
        $resolver->shouldReceive('contextFor')
            ->with($filer->empId)
            ->andReturn($filer);
        $resolver->shouldReceive('contextFor')
            ->with($target->empId)
            ->andReturn($target);

        if (! $filer->isPresident) {
            $resolver->shouldReceive('filerGroupAbbreviation')
                ->with($target->empId)
                ->andReturn($targetGroup);
        }

        return new LeaveOnBehalfAuthorizationService($resolver);
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

    private function employee(int $id): Employee
    {
        $employee = new Employee;
        $employee->id = $id;

        return $employee;
    }
}
