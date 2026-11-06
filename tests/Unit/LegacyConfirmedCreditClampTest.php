<?php

namespace Tests\Unit;

use App\Services\Leave\LeaveApprovalPolicy;
use App\Services\Leave\LeaveAttachmentService;
use App\Services\Leave\LeaveBalanceService;
use App\Services\Leave\Legacy\LegacyLeaveAttachmentCopier;
use App\Services\Leave\Legacy\LegacyLeaveMigrationService;
use App\Services\Leave\Legacy\LegacyLeaveRowMapper;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use Tests\TestCase;

class LegacyConfirmedCreditClampTest extends TestCase
{
    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: string, 4: string}>
     */
    public static function clamps(): array
    {
        return [
            'short sheet keeps the unpaid remainder' => ['9.00', '9.00', '0.00', '0.50', '8.50'],
            'empty sheet is entirely without pay' => ['2.00', '2.00', '0.00', '0.00', '2.00'],
            'negative available is treated as zero' => ['1.00', '1.00', '0.00', '-1.00', '1.00'],
            'enough credits leave an existing lwop split alone' => ['3.00', '4.00', '1.00', '10.00', '1.00'],
            'exact available is unchanged' => ['0.50', '0.50', '0.00', '0.50', '0.00'],
        ];
    }

    #[DataProvider('clamps')]
    public function test_confirmed_paid_days_shrink_to_the_sheet_balance(
        string $paidDays,
        string $duration,
        string $lwopDays,
        string $available,
        string $expectedLwop,
    ): void {
        $balance = $this->createMock(LeaveBalanceService::class);
        $balance->method('availableFor')->willReturn($available);

        $service = new LegacyLeaveMigrationService(
            new LegacyLeaveRowMapper,
            $balance,
            $this->createMock(LeaveApprovalPolicy::class),
            $this->createMock(LegacyLeaveAttachmentCopier::class),
            $this->createMock(LeaveAttachmentService::class),
        );

        $method = new ReflectionMethod($service, 'clampConfirmedPaidDays');

        $result = $method->invoke($service, [
            'emp_id' => 428,
            'paid_days' => $paidDays,
            'duration' => $duration,
            'lwop_days' => $lwopDays,
        ], 2);

        $expectedPaid = bccomp($paidDays, $available, 2) <= 0 && bccomp($available, '0', 2) >= 0
            ? $paidDays
            : (bccomp($available, '0', 2) < 0 ? '0.00' : $available);

        $this->assertSame($expectedPaid, $result['paid_days']);
        $this->assertSame($expectedLwop, $result['lwop_days']);
    }
}
