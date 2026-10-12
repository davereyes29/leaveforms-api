<?php

namespace App\Services\Leave;

use App\Enums\Leave\DayType;
use App\Enums\Leave\LeaveStatus;
use App\Models\Kdtph\Employee;
use App\Models\Leave\LeaveDailyReportEntry;
use App\Models\Leave\LeaveInfo;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Writes approved leave into webjmrdb.dailyreport.
 *
 * Called when a leave is confirmed, when an admin cancels that leave, and when
 * a conversion changes its type. It does not scan older leaves.
 */
class DailyReportLeaveSyncService
{
    private const CONNECTION = 'webjmrdb';

    private const REMARKS_MAX_LENGTH = 500;

    public function __construct(
        private readonly LeaveDurationService $durationService,
    ) {}

    public function sync(LeaveInfo $leave): void
    {
        try {
            $this->insertEntries($leave);
        } catch (Throwable $exception) {
            Log::error('Daily report leave sync failed.', [
                'leave_id' => $leave->id,
                'message' => $exception->getMessage(),
            ]);
        }
    }

    public function remove(LeaveInfo $leave): void
    {
        try {
            $this->deleteEntries($leave);
        } catch (Throwable $exception) {
            Log::error('Daily report leave removal failed.', [
                'leave_id' => $leave->id,
                'message' => $exception->getMessage(),
            ]);
        }
    }

    public function updateItem(LeaveInfo $leave): void
    {
        try {
            $this->replaceItem($leave);
        } catch (Throwable $exception) {
            Log::error('Daily report leave item update failed.', [
                'leave_id' => $leave->id,
                'message' => $exception->getMessage(),
            ]);
        }
    }

    private function insertEntries(LeaveInfo $leave): void
    {
        $leave->loadMissing('leaveType');

        if (! $this->isApproved($leave)) {
            Log::warning('Daily report leave sync skipped: leave is not approved.', [
                'leave_id' => $leave->id,
                'status' => $leave->status?->value,
            ]);

            return;
        }

        $employee = Employee::query()->with('group')->find($leave->emp_id);
        $groupAbbreviation = trim((string) ($employee?->group?->abbreviation ?? ''));

        if ($employee === null || $employee->group_id === null || $groupAbbreviation === '') {
            Log::warning('Daily report leave sync skipped: employee group is missing.', [
                'leave_id' => $leave->id,
                'emp_id' => $leave->emp_id,
            ]);

            return;
        }

        $dates = $this->reportDates($leave);

        if ($dates === []) {
            Log::warning('Daily report leave sync skipped: no workdays in the leave range.', [
                'leave_id' => $leave->id,
            ]);

            return;
        }

        $projectId = $this->leaveProjectId();

        if ($projectId === null) {
            Log::warning('Daily report leave sync skipped: Leave project was not found.', [
                'leave_id' => $leave->id,
            ]);

            return;
        }

        $itemId = $this->itemIdForLeave($leave, $projectId);

        if ($itemId === null) {
            return;
        }

        $dayType = $leave->day_type ?? DayType::Full;
        $tow = config('leave.daily_report.tow.'.$dayType->value);
        $duration = config('leave.daily_report.duration_minutes.'.$dayType->value);

        if (! is_numeric($tow) || ! is_numeric($duration)) {
            Log::warning('Daily report leave sync skipped: day type is not configured.', [
                'leave_id' => $leave->id,
                'day_type' => $dayType->value,
            ]);

            return;
        }

        $linkedDates = $leave->dailyReportEntries()
            ->pluck('report_date')
            ->map(function ($date): string {
                if ($date instanceof \DateTimeInterface) {
                    return $date->format('Y-m-d');
                }

                return substr((string) $date, 0, 10);
            })
            ->all();

        $changeLog = now()->format('YmdHis').'_'.$employee->id;

        foreach ($dates as $date) {
            if (in_array($date, $linkedDates, true)) {
                continue;
            }

            try {
                $this->insertOneDate(
                    $leave,
                    $employee->id,
                    (int) $employee->group_id,
                    $groupAbbreviation,
                    $date,
                    $projectId,
                    $itemId,
                    (int) $tow,
                    (int) $duration,
                    $changeLog,
                );
            } catch (Throwable $exception) {
                Log::error('Daily report leave sync failed for one date.', [
                    'leave_id' => $leave->id,
                    'report_date' => $date,
                    'message' => $exception->getMessage(),
                ]);
            }
        }
    }

    private function insertOneDate(
        LeaveInfo $leave,
        int $employeeId,
        int $groupId,
        string $groupAbbreviation,
        string $date,
        int $projectId,
        int $itemId,
        int $tow,
        int $duration,
        string $changeLog,
    ): void {
        $alreadyFiled = DB::connection(self::CONNECTION)
            ->table('dailyreport')
            ->where('fldEmployeeNum', $employeeId)
            ->whereDate('fldDate', $date)
            ->where('fldProject', $projectId)
            ->exists();

        if ($alreadyFiled) {
            Log::warning('Daily report leave sync skipped one date: a Leave row already exists.', [
                'leave_id' => $leave->id,
                'emp_id' => $employeeId,
                'report_date' => $date,
            ]);

            return;
        }

        $dailyReportId = DB::connection(self::CONNECTION)
            ->table('dailyreport')
            ->insertGetId([
                'fldEmployeeNum' => $employeeId,
                'fldGroup' => $groupAbbreviation,
                'fldGroupID' => $groupId,
                'fldDate' => $date,
                'fldLocation' => (int) config('leave.daily_report.location_id'),
                'fldProject' => $projectId,
                'fldItem' => $itemId,
                'fldJobRequestDescription' => null,
                'fld2D3D' => null,
                'fldRevision' => 0,
                'fldTOW' => $tow,
                'fldChecker' => null,
                'fldDuration' => $duration,
                'fldMHType' => (int) config('leave.daily_report.mh_type'),
                'fldRemarks' => $this->remarks($leave),
                'fldChangeLog' => $changeLog,
                'fldTrGroup' => null,
            ], 'fldID');

        LeaveDailyReportEntry::query()->create([
            'leave_id' => $leave->id,
            'dailyreport_id' => $dailyReportId,
            'report_date' => $date,
        ]);
    }

    private function deleteEntries(LeaveInfo $leave): void
    {
        $entries = $leave->dailyReportEntries()->get();

        if ($entries->isEmpty()) {
            return;
        }

        DB::connection(self::CONNECTION)
            ->table('dailyreport')
            ->whereIn('fldID', $entries->pluck('dailyreport_id')->all())
            ->delete();

        LeaveDailyReportEntry::query()
            ->whereIn('id', $entries->pluck('id')->all())
            ->delete();
    }

    private function replaceItem(LeaveInfo $leave): void
    {
        $leave->loadMissing('leaveType');

        $dailyReportIds = $leave->dailyReportEntries()->pluck('dailyreport_id');

        if ($dailyReportIds->isEmpty()) {
            return;
        }

        $projectId = $this->leaveProjectId();

        if ($projectId === null) {
            Log::warning('Daily report leave item update skipped: Leave project was not found.', [
                'leave_id' => $leave->id,
            ]);

            return;
        }

        $itemId = $this->itemIdForLeave($leave, $projectId);

        if ($itemId === null) {
            return;
        }

        DB::connection(self::CONNECTION)
            ->table('dailyreport')
            ->whereIn('fldID', $dailyReportIds->all())
            ->update([
                'fldItem' => $itemId,
            ]);
    }

    /**
     * @return list<string>
     */
    private function reportDates(LeaveInfo $leave): array
    {
        $start = $leave->start_date->toDateString();
        $end = $leave->end_date->toDateString();

        if ($leave->day_type !== DayType::Full) {
            return [$start];
        }

        $holidays = $this->durationService->holidayDatesBetween($start, $end);

        return $this->durationService->workdaysBetween($start, $end, $start, $end, $holidays);
    }

    private function isApproved(LeaveInfo $leave): bool
    {
        return in_array($leave->status, [
            LeaveStatus::Confirmed,
            LeaveStatus::PendingConversion,
            LeaveStatus::ConvertedToSl,
            LeaveStatus::ConvertedToEl,
        ], true);
    }

    private function leaveProjectId(): ?int
    {
        $id = DB::connection(self::CONNECTION)
            ->table('projectstable')
            ->where('fldProject', 'Leave')
            ->value('fldID');

        return $id === null ? null : (int) $id;
    }

    private function itemIdForLeave(LeaveInfo $leave, int $projectId): ?int
    {
        $code = strtoupper((string) ($leave->leaveType?->code ?? ''));
        $configuredName = config('leave.daily_report.item_names.'.$code);
        $itemName = is_string($configuredName) && $configuredName !== ''
            ? $configuredName
            : (string) ($leave->leaveType?->type ?? '');

        if ($itemName === '') {
            Log::warning('Daily report leave sync skipped: leave type name is missing.', [
                'leave_id' => $leave->id,
            ]);

            return null;
        }

        $id = DB::connection(self::CONNECTION)
            ->table('itemofworkstable')
            ->where('fldProject', $projectId)
            ->where('fldItem', $itemName)
            ->where('fldActive', 1)
            ->where('fldDelete', 0)
            ->value('fldID');

        if ($id === null) {
            Log::warning('Daily report leave sync skipped: leave item was not found.', [
                'leave_id' => $leave->id,
                'leave_type' => $code,
                'item_name' => $itemName,
            ]);

            return null;
        }

        return (int) $id;
    }

    private function remarks(LeaveInfo $leave): ?string
    {
        $remarks = trim((string) $leave->leave_reason);

        if ($remarks === '') {
            return null;
        }

        return mb_substr($remarks, 0, self::REMARKS_MAX_LENGTH);
    }
}
