<?php

namespace Tests\Unit;

use App\Enums\Leave\DayType;
use App\Enums\Leave\LeaveStatus;
use App\Services\Leave\Legacy\LegacyLeaveRowMapper;
use Tests\TestCase;

class LegacyLeaveRowMapperTest extends TestCase
{
    private LegacyLeaveRowMapper $mapper;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mapper = new LegacyLeaveRowMapper;
    }

    public function test_maps_leave_type_codes(): void
    {
        $this->assertSame('VL', $this->mapper->mapTypeCode('vleave'));
        $this->assertSame('SL', $this->mapper->mapTypeCode('sleave'));
        $this->assertSame('EL', $this->mapper->mapTypeCode('eleave'));
        $this->assertSame('ML', $this->mapper->mapTypeCode('mleave'));
        $this->assertSame('PL', $this->mapper->mapTypeCode('pleave'));
        $this->assertSame('PTB', $this->mapper->mapTypeCode('ptbleave'));
        $this->assertSame('LL', $this->mapper->mapTypeCode('lleave'));
        $this->assertNull($this->mapper->mapTypeCode('spl'));
    }

    public function test_maps_day_types(): void
    {
        $this->assertSame(DayType::Full, $this->mapper->mapDayType('0'));
        $this->assertSame(DayType::Full, $this->mapper->mapDayType(''));
        $this->assertSame(DayType::Full, $this->mapper->mapDayType('null'));
        $this->assertSame(DayType::HalfAm, $this->mapper->mapDayType('AM'));
        $this->assertSame(DayType::HalfPm, $this->mapper->mapDayType('PM'));
        $this->assertNull($this->mapper->mapDayType('FULL'));
    }

    public function test_hr_confirmed_remarks_map_to_confirmed(): void
    {
        $result = $this->mapper->resolveStatus('1', [
            ['emp_id' => 10, 'accept' => 1, 'remarks' => null, 'time' => '2026-01-02 08:00:00'],
        ]);

        $this->assertSame(LeaveStatus::Confirmed, $result['status']);
        $this->assertSame('2026-01-02 08:00:00', $result['decided_at']);
        $this->assertSame(10, $result['last_accepter_emp_id']);
    }

    public function test_all_manager_accepts_without_hr_remarks_map_to_confirmed(): void
    {
        $result = $this->mapper->resolveStatus(null, [
            ['emp_id' => 25, 'accept' => 1, 'remarks' => null, 'time' => '2026-09-01 07:00:00'],
            ['emp_id' => 281, 'accept' => 1, 'remarks' => null, 'time' => '2026-09-03 06:45:00'],
        ]);

        $this->assertSame(LeaveStatus::Confirmed, $result['status']);
        $this->assertSame(281, $result['last_accepter_emp_id']);
    }

    public function test_reject_and_cancel_remarks(): void
    {
        $rejected = $this->mapper->resolveStatus('reject', []);
        $this->assertSame(LeaveStatus::Rejected, $rejected['status']);

        $cancelled = $this->mapper->resolveStatus('cancelled leave', []);
        $this->assertSame(LeaveStatus::Cancelled, $cancelled['status']);
    }

    public function test_reject_only_accepts_map_to_rejected_or_cancelled(): void
    {
        $rejected = $this->mapper->resolveStatus(null, [
            ['emp_id' => 40, 'accept' => 0, 'remarks' => 'rejected', 'time' => null],
        ]);
        $this->assertSame(LeaveStatus::Rejected, $rejected['status']);

        $cancelled = $this->mapper->resolveStatus(null, [
            ['emp_id' => 40, 'accept' => 0, 'remarks' => 'cancelled', 'time' => null],
        ]);
        $this->assertSame(LeaveStatus::Cancelled, $cancelled['status']);
    }

    public function test_mixed_accepts_and_no_accepts_map_to_pending_approval(): void
    {
        $mixed = $this->mapper->resolveStatus(null, [
            ['emp_id' => 10, 'accept' => 1, 'remarks' => null, 'time' => null],
            ['emp_id' => 18, 'accept' => 0, 'remarks' => 'change', 'time' => null],
        ]);
        $this->assertSame(LeaveStatus::PendingApproval, $mixed['status']);

        $none = $this->mapper->resolveStatus(null, []);
        $this->assertSame(LeaveStatus::PendingApproval, $none['status']);
    }

    public function test_map_leave_row_clamps_lwop_and_skips_bad_duration(): void
    {
        $ok = $this->mapper->mapLeaveRow((object) [
            'l_id' => 100,
            'l_eid' => 270,
            'l_type' => 'vleave',
            'l_dtype' => '0',
            'l_duration' => 2,
            'l_sdate' => '2026-01-05',
            'l_edate' => '2026-01-06',
            'l_reason' => 'Vacation',
            'l_status' => '',
            'l_attachment' => '',
            'l_file' => '2026-01-04',
            'l_filedBy' => '',
            'l_remarks' => '1',
            'l_lwop' => 5,
        ], []);

        $this->assertSame('2.00', $ok['duration']);
        $this->assertSame('2.00', $ok['lwop_days']);
        $this->assertSame('0.00', $ok['paid_days']);
        $this->assertSame(270, $ok['filed_by_emp_id']);
        $this->assertSame(LeaveStatus::Confirmed, $ok['status']);
        $this->assertSame('2026-01-04 12:00:00', $ok['created_at']);

        $half = $this->mapper->mapLeaveRow((object) [
            'l_id' => 99,
            'l_eid' => 270,
            'l_type' => 'vleave',
            'l_dtype' => 'PM',
            'l_duration' => 1,
            'l_sdate' => '2026-01-05',
            'l_edate' => '2026-01-05',
            'l_reason' => 'Half',
            'l_status' => '',
            'l_attachment' => '',
            'l_file' => '2026-01-04',
            'l_filedBy' => '185',
            'l_remarks' => '1',
            'l_lwop' => 0,
        ], []);

        $this->assertSame(DayType::HalfPm, $half['day_type']);
        $this->assertSame('0.50', $half['duration']);
        $this->assertSame('0.50', $half['paid_days']);
        $this->assertSame(185, $half['filed_by_emp_id']);

        $bad = $this->mapper->mapLeaveRow((object) [
            'l_id' => 101,
            'l_eid' => 270,
            'l_type' => 'vleave',
            'l_dtype' => '0',
            'l_duration' => -0.5,
            'l_sdate' => '2026-01-05',
            'l_edate' => '2026-01-05',
            'l_reason' => 'Bad',
            'l_status' => '',
            'l_attachment' => '',
            'l_file' => '2026-01-04',
            'l_filedBy' => '185',
            'l_remarks' => '1',
            'l_lwop' => 0,
        ], []);

        $this->assertArrayHasKey('skip', $bad);

        $badDate = $this->mapper->mapLeaveRow((object) [
            'l_id' => 102,
            'l_eid' => 55,
            'l_type' => 'vleave',
            'l_dtype' => 'PM',
            'l_duration' => 0.5,
            'l_sdate' => '0024-10-04',
            'l_edate' => '0024-10-04',
            'l_reason' => 'Bad date',
            'l_status' => '',
            'l_attachment' => '',
            'l_file' => '2024-10-04',
            'l_filedBy' => '',
            'l_remarks' => null,
            'l_lwop' => 0,
        ], []);

        $this->assertArrayHasKey('skip', $badDate);
    }

    public function test_migration_approval_remarks_list_prior_accepters(): void
    {
        $this->assertSame(
            'Last to approve from Leaveforms V1',
            $this->mapper->migrationApprovalRemarks(true, true),
        );

        $this->assertSame(
            'Approved in Leaveforms V1',
            $this->mapper->migrationApprovalRemarks(true, false),
        );

        $this->assertSame(
            'Rejected in Leaveforms V1',
            $this->mapper->migrationApprovalRemarks(false),
        );
    }

    public function test_rejection_remarks_prefer_accept_row_then_leave_remarks(): void
    {
        $this->assertSame(
            'Not enough staff',
            $this->mapper->migrationRejectionRemarks('Not enough staff', 'rejected by HR'),
        );

        $this->assertSame(
            'rejected by HR',
            $this->mapper->migrationRejectionRemarks(null, 'rejected by HR'),
        );

        $this->assertSame(
            'Rejected in Leaveforms V1',
            $this->mapper->migrationRejectionRemarks('  ', '1'),
        );
    }

    public function test_rejected_leave_keeps_rejecter_remarks(): void
    {
        $rejected = $this->mapper->mapLeaveRow((object) [
            'l_id' => 201,
            'l_eid' => 270,
            'l_type' => 'vleave',
            'l_dtype' => '0',
            'l_duration' => 1,
            'l_sdate' => '2026-02-02',
            'l_edate' => '2026-02-02',
            'l_reason' => 'Personal',
            'l_status' => '',
            'l_attachment' => '',
            'l_file' => '2026-02-01',
            'l_filedBy' => '',
            'l_remarks' => null,
            'l_lwop' => 0,
        ], [
            ['emp_id' => 40, 'accept' => 0, 'remarks' => 'Schedule conflict', 'time' => '2026-02-01 09:00:00'],
        ]);

        $this->assertSame(LeaveStatus::Rejected, $rejected['status']);
        $this->assertNull($rejected['rejection_remarks']);
        $this->assertSame(
            [
                ['emp_id' => 40, 'time' => '2026-02-01 09:00:00', 'remarks' => 'Schedule conflict'],
            ],
            $rejected['accepter_entries'],
        );
        $this->assertSame(
            'Schedule conflict',
            $this->mapper->migrationRejectionRemarks(
                $rejected['accepter_entries'][0]['remarks'],
                $rejected['rejection_remarks'],
            ),
        );

        $fromLeaveRemarks = $this->mapper->mapLeaveRow((object) [
            'l_id' => 202,
            'l_eid' => 270,
            'l_type' => 'vleave',
            'l_dtype' => '0',
            'l_duration' => 1,
            'l_sdate' => '2026-02-03',
            'l_edate' => '2026-02-03',
            'l_reason' => 'Personal',
            'l_status' => '',
            'l_attachment' => '',
            'l_file' => '2026-02-01',
            'l_filedBy' => '',
            'l_remarks' => 'rejected on the form',
            'l_lwop' => 0,
        ], [
            ['emp_id' => 18, 'accept' => 0, 'remarks' => '', 'time' => '2026-02-01 10:00:00'],
        ]);

        $this->assertSame(LeaveStatus::Rejected, $fromLeaveRemarks['status']);
        $this->assertSame(
            'rejected on the form',
            $this->mapper->migrationRejectionRemarks(
                $fromLeaveRemarks['accepter_entries'][0]['remarks'],
                $fromLeaveRemarks['rejection_remarks'],
            ),
        );
    }

    public function test_accepter_emp_ids_are_unique_oldest_to_newest(): void
    {
        $accepts = [
            ['emp_id' => 185, 'accept' => 1, 'remarks' => null, 'time' => '2026-09-03 10:00:00'],
            ['emp_id' => 10, 'accept' => 1, 'remarks' => null, 'time' => '2026-09-01 08:00:00'],
            ['emp_id' => 18, 'accept' => 1, 'remarks' => null, 'time' => '2026-09-02 09:00:00'],
            ['emp_id' => 40, 'accept' => 0, 'remarks' => 'no', 'time' => '2026-09-04 11:00:00'],
            ['emp_id' => 10, 'accept' => 1, 'remarks' => null, 'time' => '2026-09-05 12:00:00'],
        ];

        $this->assertSame(
            [10, 18, 185],
            $this->mapper->accepterEmpIds($accepts),
        );

        $this->assertSame(
            [
                ['emp_id' => 10, 'time' => '2026-09-01 08:00:00'],
                ['emp_id' => 18, 'time' => '2026-09-02 09:00:00'],
                ['emp_id' => 185, 'time' => '2026-09-03 10:00:00'],
            ],
            $this->mapper->accepterEntries($accepts),
        );
    }
}
