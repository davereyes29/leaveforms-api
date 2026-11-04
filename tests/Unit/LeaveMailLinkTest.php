<?php

namespace Tests\Unit;

use App\Mail\Leave\LeaveMailLink;
use App\Models\Leave\LeaveInfo;
use App\Models\Leave\LeaveType;
use Tests\TestCase;

class LeaveMailLinkTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['leave.frontend_url' => 'http://localhost:5173/']);
    }

    public function test_paths_are_appended_to_the_frontend_origin(): void
    {
        $this->assertSame(
            'http://localhost:5173/team/leave-approvals/15',
            LeaveMailLink::approverReview(15),
        );
        $this->assertSame(
            'http://localhost:5173/team/leave-approvals',
            LeaveMailLink::approverQueue(),
        );
        $this->assertSame(
            'http://localhost:5173/admin/leave-requests/15/validate',
            LeaveMailLink::adminValidate(15),
        );
        $this->assertSame(
            'http://localhost:5173/admin/leave-requests/15',
            LeaveMailLink::adminView(15),
        );
        $this->assertSame(
            'http://localhost:5173/my-leave/requests/15',
            LeaveMailLink::employeeView(15),
        );
    }

    public function test_blank_frontend_url_hides_the_button_target(): void
    {
        config(['leave.frontend_url' => '']);

        $this->assertSame('', LeaveMailLink::approverReview(15));
    }

    public function test_pending_review_opens_admin_validate_for_admin_approved_types(): void
    {
        $this->assertSame(
            'http://localhost:5173/admin/leave-requests/15/validate',
            LeaveMailLink::pendingReview($this->leave('SL')),
        );
        $this->assertSame(
            'http://localhost:5173/team/leave-approvals/15',
            LeaveMailLink::pendingReview($this->leave('VL')),
        );
    }

    public function test_cancellation_notice_opens_the_record_for_admin_approved_types(): void
    {
        $this->assertSame(
            'http://localhost:5173/admin/leave-requests/15',
            LeaveMailLink::cancellationNotice($this->leave('SL')),
        );
        $this->assertSame(
            'http://localhost:5173/team/leave-approvals/15',
            LeaveMailLink::cancellationNotice($this->leave('VL')),
        );
    }

    private function leave(string $code): LeaveInfo
    {
        $leave = new LeaveInfo;
        $leave->id = 15;
        $leave->setRelation('leaveType', new LeaveType(['code' => $code]));

        return $leave;
    }
}
