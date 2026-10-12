<?php

namespace App\Services\Leave\Legacy;

use App\Enums\Leave\DayType;
use App\Enums\Leave\LeaveStatus;

/**
 * Pure mapping from legacy formsdb leave_info (+ leave_accept) into revamp fields.
 */
final class LegacyLeaveRowMapper
{
    public const TYPE_MAP = [
        'vleave' => 'VL',
        'sleave' => 'SL',
        'eleave' => 'EL',
        'mleave' => 'ML',
        'pleave' => 'PL',
        'ptbleave' => 'PTB',
        'lleave' => 'LL',
    ];

    public const BALANCE_TYPE_MAP = [
        'lc_vl' => 'VL',
        'lc_sl' => 'SL',
        'lc_el' => 'EL',
        'lc_ptbl' => 'PTB',
    ];

    public const REMARKS_PREFIX = 'Legacy migration from leave_confirmation';

    /**
     * @param  list<array{emp_id: int, accept: int, remarks: ?string, time: ?string}>  $accepts
     * @return array{
     *     status: LeaveStatus,
     *     day_type: DayType,
     *     leave_type_code: string,
     *     emp_id: int,
     *     filed_by_emp_id: int,
     *     duration: string,
     *     paid_days: string,
     *     lwop_days: string,
     *     start_date: string,
     *     end_date: string,
     *     leave_reason: string,
     *     cancellation_reason: ?string,
     *     confirmed_at: ?string,
     *     created_at: string,
     *     updated_at: string,
     *     last_accepter_emp_id: ?int,
     * }|array{skip: string}
     */
    public function mapLeaveRow(object $row, array $accepts = []): array
    {
        $legacyId = (int) $row->l_id;
        $typeCode = self::TYPE_MAP[strtolower((string) $row->l_type)] ?? null;

        if ($typeCode === null) {
            return ['skip' => "l_id {$legacyId}: unknown leave type [{$row->l_type}]"];
        }

        $dayType = $this->mapDayType((string) ($row->l_dtype ?? '0'));

        if ($dayType === null) {
            return ['skip' => "l_id {$legacyId}: unknown day type [{$row->l_dtype}]"];
        }

        // Legacy often stored AM/PM with l_duration=1; revamp half-days are always 0.5.
        $duration = $dayType === DayType::Full
            ? $this->decimal((string) $row->l_duration)
            : '0.50';

        if (bccomp($duration, '0', 2) <= 0) {
            return ['skip' => "l_id {$legacyId}: non-positive duration [{$row->l_duration}]"];
        }

        $startDate = $this->normalizeDate((string) $row->l_sdate);
        $endDate = $this->normalizeDate((string) $row->l_edate);

        if ($startDate === null || $endDate === null) {
            return ['skip' => "l_id {$legacyId}: invalid dates [{$row->l_sdate} / {$row->l_edate}]"];
        }

        $lwopRaw = $this->decimal((string) ($row->l_lwop ?? '0'));
        if (bccomp($lwopRaw, '0', 2) < 0) {
            $lwopRaw = '0.00';
        }

        $lwopDays = bccomp($lwopRaw, $duration, 2) > 0 ? $duration : $lwopRaw;
        $paidDays = bcsub($duration, $lwopDays, 2);

        $empId = (int) $row->l_eid;
        $filedBy = trim((string) ($row->l_filedBy ?? ''));
        $filedByEmpId = $filedBy !== '' && ctype_digit($filedBy) ? (int) $filedBy : $empId;

        if ($empId < 1 || $filedByEmpId < 1) {
            return ['skip' => "l_id {$legacyId}: invalid emp_id / filed_by"];
        }

        $statusDecision = $this->resolveStatus($row->l_remarks ?? null, $accepts);

        if (isset($statusDecision['skip'])) {
            return ['skip' => "l_id {$legacyId}: {$statusDecision['skip']}"];
        }

        /** @var LeaveStatus $status */
        $status = $statusDecision['status'];
        $createdAt = $this->createdAt((string) ($row->l_file ?? ''), $startDate);
        $confirmedAt = null;

        if ($status === LeaveStatus::Confirmed) {
            $confirmedAt = $statusDecision['decided_at'] ?? $createdAt;
        }

        $cancellationReason = null;
        if ($status === LeaveStatus::Cancelled) {
            $cancellationReason = $this->cancellationReason($row->l_remarks ?? null, $accepts);
        }

        // Fresh pending under revamp rules — do not replay partial V1 leave_accept rows.
        // Rejected leaves keep the rejecter (fldAccept=0) so their legacy remarks survive.
        $rejectionRemarks = null;
        if ($status === LeaveStatus::PendingApproval) {
            $accepterEntries = [];
        } elseif ($status === LeaveStatus::Rejected) {
            $accepterEntries = $this->rejecterEntries($accepts);
            $leaveRemarks = trim((string) ($row->l_remarks ?? ''));
            $rejectionRemarks = ($leaveRemarks !== '' && $leaveRemarks !== '1') ? $leaveRemarks : null;
        } else {
            $accepterEntries = $this->accepterEntries($accepts);
        }

        return [
            'status' => $status,
            'day_type' => $dayType,
            'leave_type_code' => $typeCode,
            'emp_id' => $empId,
            'filed_by_emp_id' => $filedByEmpId,
            'duration' => $duration,
            'paid_days' => $paidDays,
            'lwop_days' => $lwopDays,
            'start_date' => $startDate,
            'end_date' => $endDate,
            'leave_reason' => (string) ($row->l_reason ?? ''),
            'cancellation_reason' => $cancellationReason,
            'confirmed_at' => $confirmedAt,
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
            'last_accepter_emp_id' => $statusDecision['last_accepter_emp_id'] ?? null,
            'accepter_entries' => $accepterEntries,
            'rejection_remarks' => $rejectionRemarks,
        ];
    }

    /**
     * Unique accepters (fldAccept=1), oldest → newest, with their action time.
     *
     * @param  list<array{emp_id: int, accept: int, remarks: ?string, time: ?string}>  $accepts
     * @return list<array{emp_id: int, time: ?string}>
     */
    public function accepterEntries(array $accepts): array
    {
        $accepted = array_values(array_filter(
            $accepts,
            static fn (array $row): bool => (int) $row['accept'] === 1,
        ));

        usort($accepted, static function (array $a, array $b): int {
            return strcmp((string) ($a['time'] ?? ''), (string) ($b['time'] ?? ''));
        });

        $entries = [];
        $seen = [];
        foreach ($accepted as $row) {
            $id = (int) $row['emp_id'];
            if ($id < 1 || isset($seen[$id])) {
                continue;
            }
            $seen[$id] = true;
            $time = $row['time'] ?? null;
            $entries[] = [
                'emp_id' => $id,
                'time' => $time !== null && trim((string) $time) !== '' ? (string) $time : null,
            ];
        }

        return $entries;
    }

    /**
     * Unique rejecters (fldAccept=0), oldest → newest, with their action time and remarks.
     *
     * @param  list<array{emp_id: int, accept: int, remarks: ?string, time: ?string}>  $accepts
     * @return list<array{emp_id: int, time: ?string, remarks: ?string}>
     */
    public function rejecterEntries(array $accepts): array
    {
        $rejected = array_values(array_filter(
            $accepts,
            static fn (array $row): bool => (int) $row['accept'] === 0,
        ));

        usort($rejected, static function (array $a, array $b): int {
            return strcmp((string) ($a['time'] ?? ''), (string) ($b['time'] ?? ''));
        });

        $entries = [];
        $indexByEmp = [];
        foreach ($rejected as $row) {
            $id = (int) $row['emp_id'];
            if ($id < 1) {
                continue;
            }

            $time = $row['time'] ?? null;
            $time = $time !== null && trim((string) $time) !== '' ? (string) $time : null;
            $remarks = trim((string) ($row['remarks'] ?? ''));
            $remarks = $remarks !== '' ? $remarks : null;

            if (isset($indexByEmp[$id])) {
                $index = $indexByEmp[$id];
                if ($entries[$index]['remarks'] === null && $remarks !== null) {
                    $entries[$index]['remarks'] = $remarks;
                }

                continue;
            }

            $indexByEmp[$id] = count($entries);
            $entries[] = [
                'emp_id' => $id,
                'time' => $time,
                'remarks' => $remarks,
            ];
        }

        return $entries;
    }

    /**
     * @param  list<array{emp_id: int, accept: int, remarks: ?string, time: ?string}>  $accepts
     * @return list<int>
     */
    public function accepterEmpIds(array $accepts): array
    {
        return array_map(
            static fn (array $entry): int => $entry['emp_id'],
            $this->accepterEntries($accepts),
        );
    }

    /**
     * Per-row stub remarks for Approval Details on migrated leaves.
     *
     * @param  bool  $isLast  true for the chronologically last accepter
     */
    public function migrationApprovalRemarks(bool $approved, bool $isLast = true): string
    {
        if (! $approved) {
            return 'Rejected in Leaveforms V1';
        }

        return $isLast
            ? 'Last to approve from Leaveforms V1'
            : 'Approved in Leaveforms V1';
    }

    /**
     * Pending leaves that start before the credit-sheet cutoff are closed as
     * rejected so migration does not reserve credits. Leaves on or after the
     * cutoff stay pending. Confirmed, rejected, and cancelled rows are unchanged.
     *
     * @param  array<string, mixed>  $mapped
     * @return array<string, mixed>
     */
    public function rejectPendingBeforeCutoff(array $mapped, string $cutoff): array
    {
        $cutoff = trim($cutoff);

        if ($cutoff === '' || ($mapped['status'] ?? null) !== LeaveStatus::PendingApproval) {
            return $mapped;
        }

        $startDate = (string) ($mapped['start_date'] ?? '');

        if ($startDate === '' || $startDate >= $cutoff) {
            return $mapped;
        }

        $mapped['status'] = LeaveStatus::Rejected;
        $mapped['accepter_entries'] = [];
        $mapped['last_accepter_emp_id'] = null;
        $mapped['confirmed_at'] = null;
        $mapped['rejection_remarks'] = "Rejected during legacy migration: pending leave starts before {$cutoff} and is not counted against the credit sheet.";

        return $mapped;
    }

    /**
     * A confirmed leave on or after the cutoff is not already inside the credit
     * sheet, so its paid days must be deducted. Earlier dates and other statuses
     * are unchanged.
     */
    public function confirmedLeaveUsesCreditSheetBalance(LeaveStatus $status, string $startDate, string $cutoff): bool
    {
        $cutoff = trim($cutoff);

        if ($cutoff === '' || $status !== LeaveStatus::Confirmed || $startDate === '') {
            return false;
        }

        return $startDate >= $cutoff;
    }

    /**
     * Reject history remarks: the rejecter's leave_accept text, then leave_info.l_remarks.
     */
    public function migrationRejectionRemarks(?string $acceptRemarks, ?string $leaveRemarks = null): string
    {
        $acceptText = trim((string) $acceptRemarks);
        if ($acceptText !== '') {
            return $acceptText;
        }

        $leaveText = trim((string) $leaveRemarks);
        if ($leaveText !== '' && $leaveText !== '1') {
            return $leaveText;
        }

        return 'Rejected in Leaveforms V1';
    }

    public function mapDayType(string $dtype): ?DayType
    {
        return match (strtoupper(trim($dtype))) {
            '', '0', 'NULL' => DayType::Full,
            'AM' => DayType::HalfAm,
            'PM' => DayType::HalfPm,
            default => null,
        };
    }

    public function mapTypeCode(string $legacyType): ?string
    {
        return self::TYPE_MAP[strtolower(trim($legacyType))] ?? null;
    }

    /**
     * @param  list<array{emp_id: int, accept: int, remarks: ?string, time: ?string}>  $accepts
     * @return array{status: LeaveStatus, decided_at?: string, last_accepter_emp_id?: ?int}|array{skip: string}
     */
    public function resolveStatus(mixed $remarks, array $accepts): array
    {
        $remarksText = $remarks === null ? null : trim((string) $remarks);
        $remarksLower = $remarksText === null ? '' : strtolower($remarksText);

        if ($remarksText === '1') {
            return [
                'status' => LeaveStatus::Confirmed,
                'decided_at' => $this->latestAcceptTime($accepts),
                'last_accepter_emp_id' => $this->lastAccepterEmpId($accepts, acceptedOnly: true),
            ];
        }

        if ($remarksText !== null && $remarksText !== '' && str_contains($remarksLower, 'cancel')) {
            return [
                'status' => LeaveStatus::Cancelled,
                'last_accepter_emp_id' => $this->lastAccepterEmpId($accepts, acceptedOnly: false),
            ];
        }

        if ($remarksText !== null && $remarksText !== '' && str_contains($remarksLower, 'reject')) {
            return [
                'status' => LeaveStatus::Rejected,
                'last_accepter_emp_id' => $this->lastAccepterEmpId($accepts, acceptedOnly: false),
            ];
        }

        if ($accepts === []) {
            return [
                'status' => LeaveStatus::PendingApproval,
                'last_accepter_emp_id' => null,
            ];
        }

        $values = array_map(static fn (array $row): int => (int) $row['accept'], $accepts);
        $allAccepted = ! in_array(0, $values, true);
        $allRejected = ! in_array(1, $values, true);

        if ($allAccepted) {
            return [
                'status' => LeaveStatus::Confirmed,
                'decided_at' => $this->latestAcceptTime($accepts),
                'last_accepter_emp_id' => $this->lastAccepterEmpId($accepts, acceptedOnly: true),
            ];
        }

        if ($allRejected) {
            $cancelFromAccept = false;
            foreach ($accepts as $accept) {
                $text = strtolower(trim((string) ($accept['remarks'] ?? '')));
                if ($text !== '' && str_contains($text, 'cancel')) {
                    $cancelFromAccept = true;
                    break;
                }
            }

            return [
                'status' => $cancelFromAccept ? LeaveStatus::Cancelled : LeaveStatus::Rejected,
                'last_accepter_emp_id' => $this->lastAccepterEmpId($accepts, acceptedOnly: false),
            ];
        }

        // Partial V1 chain — import as fresh pending under revamp rules (ignore legacy ticks).
        return [
            'status' => LeaveStatus::PendingApproval,
            'last_accepter_emp_id' => null,
        ];
    }

    /**
     * @param  list<array{emp_id: int, accept: int, remarks: ?string, time: ?string}>  $accepts
     */
    private function latestAcceptTime(array $accepts): ?string
    {
        $latest = null;

        foreach ($accepts as $accept) {
            $time = $accept['time'] ?? null;
            if ($time === null || trim($time) === '') {
                continue;
            }
            if ($latest === null || strcmp($time, $latest) > 0) {
                $latest = $time;
            }
        }

        return $latest;
    }

    /**
     * @param  list<array{emp_id: int, accept: int, remarks: ?string, time: ?string}>  $accepts
     */
    private function lastAccepterEmpId(array $accepts, bool $acceptedOnly): ?int
    {
        $chosen = null;
        $chosenTime = null;

        foreach ($accepts as $accept) {
            if ($acceptedOnly && (int) $accept['accept'] !== 1) {
                continue;
            }

            $time = $accept['time'] ?? '';
            if ($chosen === null || strcmp((string) $time, (string) $chosenTime) >= 0) {
                $chosen = (int) $accept['emp_id'];
                $chosenTime = $time;
            }
        }

        return $chosen;
    }

    /**
     * @param  list<array{emp_id: int, accept: int, remarks: ?string, time: ?string}>  $accepts
     */
    private function cancellationReason(?string $remarks, array $accepts): string
    {
        $text = trim((string) $remarks);
        if ($text !== '') {
            return $text;
        }

        foreach ($accepts as $accept) {
            $acceptRemarks = trim((string) ($accept['remarks'] ?? ''));
            if ($acceptRemarks !== '') {
                return $acceptRemarks;
            }
        }

        return 'Migrated from legacy leaveforms (cancelled)';
    }

    private function normalizeDate(string $value): ?string
    {
        $value = trim($value);
        if ($value === '' || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return null;
        }

        $year = (int) substr($value, 0, 4);
        if ($year < 2000 || $year > 2100) {
            return null;
        }

        [$y, $m, $d] = array_map('intval', explode('-', $value));
        if (! checkdate($m, $d, $y)) {
            return null;
        }

        return $value;
    }

    private function createdAt(string $fileDate, string $fallbackDate): string
    {
        $normalized = $this->normalizeDate($fileDate);

        return ($normalized ?? $fallbackDate).' 12:00:00';
    }

    private function decimal(string $value): string
    {
        $value = trim($value);
        if ($value === '' || ! is_numeric($value)) {
            return '0.00';
        }

        return number_format((float) $value, 2, '.', '');
    }
}
