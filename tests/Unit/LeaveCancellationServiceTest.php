<?php

namespace Tests\Unit;

use App\Enums\Leave\LeaveStatus;
use App\Models\Kdtph\Employee;
use App\Models\Leave\LeaveInfo;
use App\Services\Leave\ApproverContext;
use App\Services\Leave\LeaveApproverResolver;
use App\Services\Leave\LeaveBalanceService;
use App\Services\Leave\LeaveCancellationService;
use App\Services\Leave\LeaveNotificationService;
use App\Services\Leave\VacationLeaveQuarterNoticeService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;
use Mockery;
use Tests\TestCase;

class LeaveCancellationServiceTest extends TestCase
{
    public function test_non_admin_cannot_cancel_confirmed_leave(): void
    {
        $service = $this->service(isAdmin: false);

        $this->expectException(AuthorizationException::class);
        $this->expectExceptionMessage('Only admin can cancel a confirmed leave.');

        $service->cancelConfirmedByAdmin(
            $this->leave(LeaveStatus::Confirmed),
            $this->employee(99),
            'Will report to the office.',
        );
    }

    public function test_admin_cannot_cancel_pending_leave(): void
    {
        $service = $this->service(isAdmin: true);

        try {
            $service->cancelConfirmedByAdmin(
                $this->leave(LeaveStatus::PendingApproval),
                $this->employee(1),
                'Will report to the office.',
            );
            $this->fail('Expected ValidationException');
        } catch (ValidationException $exception) {
            $this->assertSame(
                ['Only confirmed leave can be cancelled by admin.'],
                $exception->errors()['leave'],
            );
        }
    }

    public function test_admin_cannot_cancel_leave_with_pending_conversion(): void
    {
        $service = $this->service(isAdmin: true);

        try {
            $service->cancelConfirmedByAdmin(
                $this->leave(LeaveStatus::PendingConversion),
                $this->employee(1),
                'Will report to the office.',
            );
            $this->fail('Expected ValidationException');
        } catch (ValidationException $exception) {
            $this->assertSame(
                ['Leave with a pending conversion cannot be cancelled. Reject the conversion first, then cancel the confirmed leave.'],
                $exception->errors()['leave'],
            );
        }
    }

    public function test_admin_cannot_cancel_converted_leave(): void
    {
        $service = $this->service(isAdmin: true);

        try {
            $service->cancelConfirmedByAdmin(
                $this->leave(LeaveStatus::ConvertedToSl),
                $this->employee(1),
                'Will report to the office.',
            );
            $this->fail('Expected ValidationException');
        } catch (ValidationException $exception) {
            $this->assertSame(
                ['Only confirmed leave can be cancelled by admin.'],
                $exception->errors()['leave'],
            );
        }
    }

    public function test_employee_still_cannot_cancel_confirmed_leave(): void
    {
        $service = $this->service(isAdmin: false);

        try {
            $service->cancel(
                $this->leave(LeaveStatus::Confirmed, empId: 8),
                $this->employee(8),
                'Will report to the office.',
            );
            $this->fail('Expected ValidationException');
        } catch (ValidationException $exception) {
            $this->assertSame(
                ['Only pending leave can be cancelled.'],
                $exception->errors()['leave'],
            );
        }
    }

    private function service(bool $isAdmin): LeaveCancellationService
    {
        $resolver = Mockery::mock(LeaveApproverResolver::class);
        $resolver->shouldReceive('contextFor')->andReturn(new ApproverContext(
            empId: 1,
            approvalLevel: null,
            groupCodes: [],
            isPresident: false,
            isAdmin: $isAdmin,
        ));

        return new LeaveCancellationService(
            Mockery::mock(LeaveBalanceService::class),
            Mockery::mock(LeaveNotificationService::class),
            $resolver,
            Mockery::mock(VacationLeaveQuarterNoticeService::class),
        );
    }

    private function leave(LeaveStatus $status, int $empId = 8): LeaveInfo
    {
        $leave = new LeaveInfo;
        $leave->id = 1;
        $leave->emp_id = $empId;
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
