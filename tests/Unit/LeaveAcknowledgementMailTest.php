<?php

namespace Tests\Unit;

use App\Mail\Leave\LeaveCancelledByAdminMail;
use App\Mail\Leave\LeaveConfirmedMail;
use App\Mail\Leave\LeaveRejectedMail;
use App\Models\Leave\LeaveInfo;
use App\Models\Leave\LeaveType;
use Tests\TestCase;

class LeaveAcknowledgementMailTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['leave.frontend_url' => 'http://localhost:5173']);
    }

    public function test_on_behalf_filer_approval_names_the_employee_on_leave(): void
    {
        $mail = new LeaveConfirmedMail(
            leave: $this->leave(),
            employeeName: 'Eugene Viñas',
            recipientName: 'Marcial',
            approverName: 'Marcial Mendoza',
            forFiler: true,
        );

        $html = $mail->render();

        $this->assertSame(
            'Leave request for Eugene Viñas has been approved',
            $mail->envelope()->subject,
        );
        $this->assertStringContainsString("Eugene Viñas's Vacation Leave request has been approved", $html);
        $this->assertStringContainsString('you filed for', $html);
        $this->assertStringContainsString('Eugene Viñas', $html);
        $this->assertStringContainsString('Marcial Mendoza', $html);
        $this->assertStringNotContainsString('Employee No.', $html);
        $this->assertStringNotContainsString('Employee Name', $html);
        $this->assertStringNotContainsString('Your Vacation Leave request has been approved', $html);
    }

    public function test_employee_on_leave_approval_keeps_your_wording(): void
    {
        $mail = new LeaveConfirmedMail(
            leave: $this->leave(),
            employeeName: 'Eugene Viñas',
            recipientName: 'Eugene',
            approverName: 'Marcial Mendoza',
            forFiler: false,
        );

        $html = $mail->render();

        $this->assertSame('Your leave request has been approved', $mail->envelope()->subject);
        $this->assertStringContainsString('Your Vacation Leave request has been approved', $html);
        $this->assertStringNotContainsString('you filed for', $html);
        $this->assertStringNotContainsString('Employee No.', $html);
    }

    public function test_on_behalf_filer_rejection_names_the_employee_on_leave(): void
    {
        $mail = new LeaveRejectedMail(
            leave: $this->leave(),
            employeeName: 'Eugene Viñas',
            recipientName: 'Marcial',
            approverName: 'Marcial Mendoza',
            remarks: 'Dates overlap a holiday.',
            forFiler: true,
        );

        $html = $mail->render();

        $this->assertSame(
            'Leave request for Eugene Viñas has been rejected',
            $mail->envelope()->subject,
        );
        $this->assertStringContainsString('you filed for', $html);
        $this->assertStringContainsString('Eugene Viñas', $html);
        $this->assertStringNotContainsString('Employee No.', $html);
        $this->assertStringNotContainsString('Employee Name', $html);
        $this->assertStringContainsString('Dates overlap a holiday.', $html);
        $this->assertStringNotContainsString('Your Vacation Leave request has been rejected', $html);
    }

    public function test_on_behalf_filer_cancellation_names_the_employee_on_leave(): void
    {
        $mail = new LeaveCancelledByAdminMail(
            leave: $this->leave(),
            employeeName: 'Eugene Viñas',
            recipientName: 'Marcial',
            adminName: 'Admin User',
            cancellationReason: 'Filed for the wrong dates.',
            forFiler: true,
        );

        $html = $mail->render();

        $this->assertSame(
            'Leave request for Eugene Viñas has been cancelled',
            $mail->envelope()->subject,
        );
        $this->assertStringContainsString('you filed for', $html);
        $this->assertStringContainsString('Filed for the wrong dates.', $html);
        $this->assertStringNotContainsString('Your Vacation Leave request has been cancelled', $html);
    }

    private function leave(): LeaveInfo
    {
        $leave = new LeaveInfo([
            'emp_id' => 436,
            'filed_by_emp_id' => 1,
            'duration' => 1,
            'start_date' => '2026-11-09',
            'end_date' => '2026-11-09',
            'leave_reason' => 'Wellness Break',
        ]);
        $leave->id = 88;
        $leave->setRelation('leaveType', new LeaveType([
            'code' => 'VL',
            'type' => 'Vacation Leave',
        ]));

        return $leave;
    }
}
