<?php

namespace Tests\Unit;

use App\Enums\Leave\ActivityType;
use App\Enums\Leave\BalanceTransactionReason;
use App\Http\Resources\LeaveActivityResource;
use App\Models\Leave\LeaveActivityHistory;
use App\Models\Leave\LeaveBalanceTransaction;
use App\Models\Leave\LeaveType;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Tests\TestCase;

class LeaveActivityResourceTest extends TestCase
{
    public function test_credit_grant_row_uses_activity_leave_type_and_null_leave_id(): void
    {
        $leaveType = new LeaveType;
        $leaveType->id = 1;
        $leaveType->code = 'VL';
        $leaveType->type = 'Vacation Leave';

        $ledger = new LeaveBalanceTransaction;
        $ledger->id = 201;
        $ledger->reason = BalanceTransactionReason::AnnualEntitlement;
        $ledger->available_delta = '15.00';
        $ledger->reserved_delta = '0.00';
        $ledger->setRelation('leaveType', $leaveType);

        $activity = new LeaveActivityHistory;
        $activity->id = 88;
        $activity->leave_id = null;
        $activity->leave_type_id = 1;
        $activity->performed_by_emp_id = 12;
        $activity->activity_type = ActivityType::AnnualCreditsDistributed;
        $activity->remarks = 'Annual entitlement 2026';
        $activity->created_at = CarbonImmutable::parse('2026-01-01T01:00:00+00:00');
        $activity->setRelation('leaveType', $leaveType);
        $activity->setRelation('leaveInfo', null);
        $activity->setAttribute('performed_by_name', 'Ariane Derigay');
        $activity->setAttribute('balance_impact', '15.0 VL credited');
        $activity->setAttribute('ledger', [$ledger]);

        $payload = (new LeaveActivityResource($activity))->toArray(new Request);

        $this->assertNull($payload['leave_id']);
        $this->assertSame('annual_credits_distributed', $payload['activity_type']);
        $this->assertSame('Annual leave credits distributed', $payload['activity']);
        $this->assertSame(
            ['id' => 1, 'code' => 'VL', 'name' => 'Vacation Leave'],
            $payload['leave_type'],
        );
        $this->assertSame('15.0 VL credited', $payload['balance_impact']);
        $this->assertSame('annual_entitlement', $payload['ledger'][0]['reason']);
        $this->assertSame('15.00', $payload['ledger'][0]['available_delta']);
        $this->assertSame(12, $payload['performed_by']['id']);
        $this->assertSame('Ariane Derigay', $payload['performed_by']['name']);
        $this->assertSame('Annual entitlement 2026', $payload['remarks']);
    }

    public function test_manual_adjust_row_uses_credits_adjusted_label(): void
    {
        $leaveType = new LeaveType;
        $leaveType->id = 2;
        $leaveType->code = 'SL';
        $leaveType->type = 'Sick Leave';

        $activity = new LeaveActivityHistory;
        $activity->id = 89;
        $activity->leave_id = null;
        $activity->activity_type = ActivityType::CreditsAdjusted;
        $activity->performed_by_emp_id = 12;
        $activity->remarks = 'Opening balance overstated';
        $activity->setRelation('leaveType', $leaveType);
        $activity->setRelation('leaveInfo', null);
        $activity->setAttribute('performed_by_name', 'Ariane Derigay');
        $activity->setAttribute('balance_impact', '2.0 SL deducted');
        $activity->setAttribute('ledger', []);

        $payload = (new LeaveActivityResource($activity))->toArray(new Request);

        $this->assertNull($payload['leave_id']);
        $this->assertSame('credits_adjusted', $payload['activity_type']);
        $this->assertSame('Leave credits adjusted', $payload['activity']);
        $this->assertSame('SL', $payload['leave_type']['code']);
        $this->assertSame('2.0 SL deducted', $payload['balance_impact']);
    }
}
