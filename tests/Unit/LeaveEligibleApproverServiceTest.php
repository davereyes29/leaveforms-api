<?php

namespace Tests\Unit;

use App\Enums\Leave\ApproverType;
use App\Enums\Leave\LeaveStatus;
use App\Models\Leave\LeaveApprovalHistory;
use App\Models\Leave\LeaveApprovalRequirement;
use App\Models\Leave\LeaveInfo;
use App\Services\Leave\LeaveEligibleApproverService;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class LeaveEligibleApproverServiceTest extends TestCase
{
    /**
     * @return array<string, array{0: LeaveStatus}>
     */
    public static function conversionStatuses(): array
    {
        return [
            'pending conversion' => [LeaveStatus::PendingConversion],
            'converted to sick leave' => [LeaveStatus::ConvertedToSl],
            'converted to emergency leave' => [LeaveStatus::ConvertedToEl],
        ];
    }

    #[DataProvider('conversionStatuses')]
    public function test_imported_conversion_does_not_list_the_original_approvers(LeaveStatus $status): void
    {
        config(['leave.admin_emp_id' => 0]);

        $history = new LeaveApprovalHistory;
        $history->approver_emp_id = 22;
        $history->approver_type = ApproverType::Am;

        $requirement = new LeaveApprovalRequirement;
        $requirement->setRelation('history', $history);

        $leave = new LeaveInfo;
        $leave->legacy_leave_id = 5;
        $leave->status = $status;
        $leave->setRelation('approvalRequirements', collect([$requirement]));

        $result = app(LeaveEligibleApproverService::class)->forLeave($leave);

        $this->assertSame([], $result);
    }
}
