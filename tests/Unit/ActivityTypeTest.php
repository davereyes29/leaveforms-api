<?php

namespace Tests\Unit;

use App\Enums\Leave\ActivityType;
use Tests\TestCase;

class ActivityTypeTest extends TestCase
{
    public function test_credit_grant_types(): void
    {
        $this->assertTrue(ActivityType::AnnualCreditsDistributed->isCreditGrant());
        $this->assertTrue(ActivityType::RegularizationCreditsDistributed->isCreditGrant());
        $this->assertTrue(ActivityType::ContractualCreditsDistributed->isCreditGrant());
        $this->assertTrue(ActivityType::CreditsAdjusted->isCreditGrant());
        $this->assertFalse(ActivityType::Submitted->isCreditGrant());
        $this->assertFalse(ActivityType::LeaveConfirmed->isCreditGrant());
        $this->assertFalse(ActivityType::ConversionApproved->isCreditGrant());
    }
}
