<?php

namespace App\Services\Leave\Legacy;

use App\Enums\Leave\ApproverType;
use App\Enums\Leave\LeaveStatus;
use App\Enums\Leave\RequiredApproverType;
use App\Models\Leave\LeaveApprovalHistory;
use App\Models\Leave\LeaveApprovalRequirement;
use App\Models\Leave\LeaveBalance;
use App\Models\Leave\LeaveBalanceTransaction;
use App\Models\Leave\LeaveInfo;
use App\Models\Leave\LeaveType;
use App\Services\Leave\LeaveApprovalPolicy;
use App\Services\Leave\LeaveAttachmentService;
use App\Services\Leave\LeaveBalanceService;
use App\Services\Leave\LeaveTypeRules;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/**
 * Imports legacy formsdb leave_info / leave_confirmation into leaveforms_db.
 *
 * Does not call submit/approve services — history rows only, no credit side effects.
 */
class LegacyLeaveMigrationService
{
    public function __construct(
        private readonly LegacyLeaveRowMapper $mapper,
        private readonly LeaveBalanceService $balanceService,
        private readonly LeaveApprovalPolicy $approvalPolicy,
        private readonly LegacyLeaveAttachmentCopier $attachmentCopier,
        private readonly LeaveAttachmentService $attachmentService,
    ) {}

    /**
     * @return array{
     *     dry_run: bool,
     *     leaves: array{read: int, inserted: int, pending_inserted: int, skipped_existing: int, skipped: int, skipped_reasons: array<string, int>, attachments: array{copied: int, missing: int, skipped_existing: int, skipped_no_name: int}},
     *     balances: array{read: int, inserted: int, skipped_existing: int, skipped: int, skipped_reasons: array<string, int>},
     *     attachments: array{read: int, copied: int, missing: int, skipped_existing: int, skipped_no_name: int, skipped_reasons: array<string, int>},
     * }
     */
    public function migrate(
        bool $dryRun = true,
        bool $leaves = true,
        bool $balances = true,
        bool $fresh = false,
        bool $includePending = true,
        bool $attachmentsOnly = false,
    ): array {
        $this->assertLegacyConnection();
        $this->assertLeaveTypesSeeded();

        if ($attachmentsOnly) {
            return [
                'dry_run' => $dryRun,
                'leaves' => $this->emptyLeaveStats(),
                'balances' => $this->emptyBalanceStats(),
                'attachments' => $this->migrateAttachmentsForImportedLeaves($dryRun),
            ];
        }

        if ($fresh && ! $dryRun) {
            $this->clearPreviousImport($leaves, $balances);
        }

        $leaveStats = $this->emptyLeaveStats();
        $balanceStats = $this->emptyBalanceStats();
        $attachmentStats = $this->emptyAttachmentStats();

        // Balances first so pending reserves can draw from leave_confirmation snapshot.
        if ($balances) {
            $balanceStats = $this->migrateBalances($dryRun);
        }

        if ($leaves) {
            $leaveStats = $this->migrateLeaves($dryRun, $includePending);
            $attachmentStats = $leaveStats['attachments'];
        }

        return [
            'dry_run' => $dryRun,
            'leaves' => $leaveStats,
            'balances' => $balanceStats,
            'attachments' => $attachmentStats,
        ];
    }

    /**
     * @return array{read: int, inserted: int, pending_inserted: int, skipped_existing: int, skipped: int, skipped_reasons: array<string, int>, attachments: array{copied: int, missing: int, skipped_existing: int, skipped_no_name: int}}
     */
    private function emptyLeaveStats(): array
    {
        return [
            'read' => 0,
            'inserted' => 0,
            'pending_inserted' => 0,
            'skipped_existing' => 0,
            'skipped' => 0,
            'skipped_reasons' => [],
            'attachments' => [
                'copied' => 0,
                'missing' => 0,
                'skipped_existing' => 0,
                'skipped_no_name' => 0,
            ],
        ];
    }

    /**
     * @return array{read: int, inserted: int, skipped_existing: int, skipped: int, skipped_reasons: array<string, int>}
     */
    private function emptyBalanceStats(): array
    {
        return [
            'read' => 0,
            'inserted' => 0,
            'skipped_existing' => 0,
            'skipped' => 0,
            'skipped_reasons' => [],
        ];
    }

    /**
     * @return array{read: int, copied: int, missing: int, skipped_existing: int, skipped_no_name: int, skipped_reasons: array<string, int>}
     */
    private function emptyAttachmentStats(): array
    {
        return [
            'read' => 0,
            'copied' => 0,
            'missing' => 0,
            'skipped_existing' => 0,
            'skipped_no_name' => 0,
            'skipped_reasons' => [],
        ];
    }

    /**
     * @return array{read: int, inserted: int, pending_inserted: int, skipped_existing: int, skipped: int, skipped_reasons: array<string, int>, attachments: array{copied: int, missing: int, skipped_existing: int, skipped_no_name: int}}
     */
    private function migrateLeaves(bool $dryRun, bool $includePending): array
    {
        $stats = $this->emptyLeaveStats();

        $typeIds = LeaveType::query()->pluck('id', 'code')->all();
        $existing = LeaveInfo::query()
            ->whereNotNull('legacy_leave_id')
            ->pluck('legacy_leave_id')
            ->flip()
            ->all();

        $legacy = DB::connection('formsdb');

        $legacy->table('leave_info')
            ->orderBy('l_id')
            ->chunkById(200, function ($rows) use ($legacy, $dryRun, $includePending, $typeIds, &$existing, &$stats) {
                $ids = $rows->pluck('l_id')->all();
                $acceptsByLeave = $this->loadAccepts($legacy, $ids);

                foreach ($rows as $row) {
                    $stats['read']++;
                    $legacyId = (int) $row->l_id;

                    if (isset($existing[$legacyId])) {
                        $stats['skipped_existing']++;

                        continue;
                    }

                    $mapped = $this->mapper->mapLeaveRow(
                        $row,
                        $acceptsByLeave[$legacyId] ?? [],
                    );

                    if (isset($mapped['skip'])) {
                        $stats['skipped']++;
                        $reason = $this->skipBucket((string) $mapped['skip']);
                        $stats['skipped_reasons'][$reason] = ($stats['skipped_reasons'][$reason] ?? 0) + 1;

                        continue;
                    }

                    if (
                        $mapped['status'] === LeaveStatus::PendingApproval
                        && ! $includePending
                    ) {
                        $stats['skipped']++;
                        $stats['skipped_reasons']['pending skipped (--skip-pending)'] =
                            ($stats['skipped_reasons']['pending skipped (--skip-pending)'] ?? 0) + 1;

                        continue;
                    }

                    $typeId = $typeIds[$mapped['leave_type_code']] ?? null;
                    if ($typeId === null) {
                        $stats['skipped']++;
                        $stats['skipped_reasons']['missing leave_type'] =
                            ($stats['skipped_reasons']['missing leave_type'] ?? 0) + 1;

                        continue;
                    }

                    if ($dryRun) {
                        $stats['inserted']++;
                        if ($mapped['status'] === LeaveStatus::PendingApproval) {
                            $stats['pending_inserted']++;
                        }
                        $this->tallyAttachmentPreview($stats['attachments'], $row);
                        $existing[$legacyId] = true;

                        continue;
                    }

                    try {
                        DB::transaction(function () use ($mapped, $typeId, $legacyId, $row, &$existing, &$stats) {
                            $payload = $mapped;

                            if ($payload['status'] === LeaveStatus::PendingApproval) {
                                $payload = $this->adjustPendingPaidDays($payload, $typeId);
                            }

                            $leave = LeaveInfo::query()->create([
                                'legacy_leave_id' => $legacyId,
                                'emp_id' => $payload['emp_id'],
                                'filed_by_emp_id' => $payload['filed_by_emp_id'],
                                'leave_type_id' => $typeId,
                                'duration' => $payload['duration'],
                                'paid_days' => $payload['paid_days'],
                                'lwop_days' => $payload['lwop_days'],
                                'day_type' => $payload['day_type'],
                                'start_date' => $payload['start_date'],
                                'end_date' => $payload['end_date'],
                                'leave_reason' => $payload['leave_reason'] !== ''
                                    ? $payload['leave_reason']
                                    : 'Migrated from legacy leaveforms',
                                'status' => $payload['status'],
                                'cancellation_reason' => $payload['cancellation_reason'],
                                'confirmed_at' => null,
                            ]);

                            $leave->forceFill([
                                'created_at' => $this->manilaWallClockToUtcMysql($payload['created_at']),
                                'updated_at' => $this->manilaWallClockToUtcMysql($payload['updated_at']),
                                'confirmed_at' => $payload['confirmed_at'] !== null
                                    ? $this->manilaWallClockToUtcMysql($payload['confirmed_at'])
                                    : null,
                            ]);
                            $leave->saveQuietly();

                            if ($payload['status'] === LeaveStatus::PendingApproval) {
                                $this->createLivePendingApprovals($leave, $payload['leave_type_code']);

                                if (LeaveTypeRules::grantsCreditsOnSubmit($payload['leave_type_code'])) {
                                    $this->balanceService->grantParentalEntitlement($leave->fresh());
                                }

                                if (LeaveTypeRules::usesCredits($payload['leave_type_code'])) {
                                    $this->balanceService->reservePaidDays($leave->fresh());
                                }

                                $stats['pending_inserted']++;
                            } else {
                                $this->createApprovalStub($leave, $payload);
                            }

                            $this->tallyAttachmentResult(
                                $stats['attachments'],
                                $this->attachmentCopier->attachToLeave(
                                    $leave,
                                    (int) $row->l_eid,
                                    $row->l_sdate,
                                    $row->l_attachment ?? null,
                                ),
                            );

                            $existing[$legacyId] = true;
                            $stats['inserted']++;
                        });
                    } catch (Throwable $e) {
                        $stats['skipped']++;
                        $key = 'import error: '.$e->getMessage();
                        $stats['skipped_reasons'][$key] = ($stats['skipped_reasons'][$key] ?? 0) + 1;
                    }
                }
            }, 'l_id');

        return $stats;
    }

    /**
     * Backfill attachments for leaves already imported (legacy_leave_id set).
     *
     * @return array{read: int, copied: int, missing: int, skipped_existing: int, skipped_no_name: int, skipped_reasons: array<string, int>}
     */
    private function migrateAttachmentsForImportedLeaves(bool $dryRun): array
    {
        $stats = $this->emptyAttachmentStats();

        if (! $this->attachmentCopier->uploadsRootExists()) {
            $stats['skipped_reasons']['uploads path missing: '.$this->attachmentCopier->uploadsRoot()] = 1;

            return $stats;
        }

        $legacy = DB::connection('formsdb');

        LeaveInfo::query()
            ->whereNotNull('legacy_leave_id')
            ->orderBy('id')
            ->chunkById(200, function ($leaves) use ($legacy, $dryRun, &$stats) {
                $legacyIds = $leaves->pluck('legacy_leave_id')->all();
                $legacyRows = $legacy->table('leave_info')
                    ->whereIn('l_id', $legacyIds)
                    ->get(['l_id', 'l_eid', 'l_sdate', 'l_attachment'])
                    ->keyBy('l_id');

                foreach ($leaves as $leave) {
                    $stats['read']++;
                    $row = $legacyRows->get($leave->legacy_leave_id);
                    if ($row === null) {
                        $stats['skipped_reasons']['legacy row missing'] =
                            ($stats['skipped_reasons']['legacy row missing'] ?? 0) + 1;

                        continue;
                    }

                    try {
                        $result = $this->attachmentCopier->attachToLeave(
                            $leave,
                            (int) $row->l_eid,
                            $row->l_sdate,
                            $row->l_attachment ?? null,
                            dryRun: $dryRun,
                        );
                        $this->tallyAttachmentResult($stats, $result);
                    } catch (Throwable $e) {
                        $stats['skipped_reasons']['copy error: '.$e->getMessage()] =
                            ($stats['skipped_reasons']['copy error: '.$e->getMessage()] ?? 0) + 1;
                    }
                }
            });

        return $stats;
    }

    /**
     * @param  array{copied: int, missing: int, skipped_existing: int, skipped_no_name: int}  $bucket
     */
    private function tallyAttachmentPreview(array &$bucket, object $row): void
    {
        $filename = $this->attachmentCopier->normalizeFilename($row->l_attachment ?? null);
        if ($filename === null) {
            $bucket['skipped_no_name']++;

            return;
        }

        $source = $this->attachmentCopier->resolveSourcePath(
            (int) $row->l_eid,
            $row->l_sdate,
            $filename,
        );

        if ($source === null) {
            $bucket['missing']++;

            return;
        }

        $bucket['copied']++;
    }

    /**
     * @param  array{copied: int, missing: int, skipped_existing: int, skipped_no_name: int}  $bucket
     * @param  array{status: string, path?: string}  $result
     */
    private function tallyAttachmentResult(array &$bucket, array $result): void
    {
        $status = $result['status'];
        if (! isset($bucket[$status])) {
            return;
        }

        $bucket[$status]++;
    }

    /**
     * Clamp paid_days to remaining available for credit types (same idea as submit).
     *
     * @param  array<string, mixed>  $mapped
     * @return array<string, mixed>
     */
    private function adjustPendingPaidDays(array $mapped, int $leaveTypeId): array
    {
        $code = (string) $mapped['leave_type_code'];
        $duration = (string) $mapped['duration'];

        if (LeaveTypeRules::isUnpaid($code)) {
            $mapped['paid_days'] = '0.00';
            $mapped['lwop_days'] = $duration;

            return $mapped;
        }

        if (! LeaveTypeRules::usesCredits($code) || LeaveTypeRules::grantsCreditsOnSubmit($code)) {
            $mapped['paid_days'] = $duration;
            $mapped['lwop_days'] = '0.00';

            return $mapped;
        }

        $available = $this->balanceService->availableFor((int) $mapped['emp_id'], $leaveTypeId);

        if (! LeaveTypeRules::allowsLwop($code) && bccomp($duration, $available, 2) > 0) {
            throw new RuntimeException(
                "Insufficient {$code} credits for pending leave (need {$duration}, available {$available})",
            );
        }

        $paidDays = bccomp($duration, $available, 2) <= 0 ? $duration : $available;
        $mapped['paid_days'] = $paidDays;
        $mapped['lwop_days'] = bcsub($duration, $paidDays, 2);

        return $mapped;
    }

    private function createLivePendingApprovals(LeaveInfo $leave, string $leaveTypeCode): void
    {
        foreach ($this->approvalPolicy->requirementsFor((int) $leave->emp_id, $leaveTypeCode) as $requirement) {
            LeaveApprovalRequirement::query()->create([
                'leave_id' => $leave->id,
                'display_order' => $requirement['display_order'],
                'allowed_levels' => $requirement['allowed_levels'],
                'required_approver_type' => $requirement['required_approver_type'],
                'is_satisfied' => false,
                'satisfied_at' => null,
            ]);
        }
    }

    /**
     * @param  array{
     *     status: LeaveStatus,
     *     leave_type_code: string,
     *     last_accepter_emp_id: ?int,
     *     accepter_entries: list<array{emp_id: int, time: ?string, remarks?: ?string}>,
     *     rejection_remarks?: ?string,
     *     confirmed_at: ?string,
     *     created_at: string,
     * }  $mapped
     */
    private function createApprovalStub(LeaveInfo $leave, array $mapped): void
    {
        if (! in_array($mapped['status'], [LeaveStatus::Confirmed, LeaveStatus::Rejected], true)) {
            return;
        }

        $isAdminType = in_array($mapped['leave_type_code'], ['SL', 'PTB', 'EL', 'LL', 'ML', 'PL', 'SPL'], true);
        $approved = $mapped['status'] === LeaveStatus::Confirmed;
        $filedAtUtc = $this->manilaWallClockToUtcMysql($mapped['created_at']);
        $fallbackAction = $mapped['confirmed_at'] ?? $mapped['created_at'];

        $entries = $mapped['accepter_entries'] ?? [];
        if ($entries === []) {
            $fallbackEmpId = $mapped['last_accepter_emp_id'];
            if ($fallbackEmpId === null || $fallbackEmpId < 1) {
                $fallbackEmpId = (int) (config('leave.admin_emp_id') ?: $leave->filed_by_emp_id);
            }
            if ($fallbackEmpId > 0) {
                $entries = [[
                    'emp_id' => $fallbackEmpId,
                    'time' => $fallbackAction,
                    'remarks' => $mapped['rejection_remarks'] ?? null,
                ]];
            }
        }

        if ($entries === []) {
            return;
        }

        $lastIndex = count($entries) - 1;

        foreach ($entries as $index => $entry) {
            $actionWall = $entry['time'] ?? $fallbackAction;
            $actionAtUtc = $this->manilaWallClockToUtcMysql($actionWall);
            $isLast = $index === $lastIndex;
            $displayOrder = $index + 1;

            $requirement = LeaveApprovalRequirement::query()->create([
                'leave_id' => $leave->id,
                'display_order' => $displayOrder,
                'allowed_levels' => $isAdminType ? null : [1, 2, 3, 4],
                'required_approver_type' => $isAdminType ? RequiredApproverType::Admin : null,
                'is_satisfied' => $approved,
                'satisfied_at' => $approved ? $actionAtUtc : null,
            ]);

            $requirement->forceFill([
                'created_at' => $filedAtUtc,
                'updated_at' => $filedAtUtc,
            ]);
            $requirement->saveQuietly();

            $history = LeaveApprovalHistory::query()->create([
                'leave_id' => $leave->id,
                'requirement_id' => $requirement->id,
                'approver_emp_id' => (int) $entry['emp_id'],
                'approval_level' => $isAdminType ? null : 1,
                'approver_type' => $isAdminType ? ApproverType::Admin : ApproverType::Leader,
                'is_approved' => $approved,
                'remarks' => $approved
                    ? $this->mapper->migrationApprovalRemarks(true, $isLast)
                    : $this->mapper->migrationRejectionRemarks(
                        $entry['remarks'] ?? null,
                        $mapped['rejection_remarks'] ?? null,
                    ),
            ]);

            $history->forceFill(['created_at' => $actionAtUtc]);
            $history->saveQuietly();
        }
    }

    private function manilaWallClockToUtcMysql(string $wallClock): string
    {
        try {
            return CarbonImmutable::parse($wallClock, 'Asia/Manila')
                ->utc()
                ->format('Y-m-d H:i:s');
        } catch (Throwable) {
            return CarbonImmutable::now('UTC')->format('Y-m-d H:i:s');
        }
    }

    /**
     * @return array{read: int, inserted: int, skipped_existing: int, skipped: int, skipped_reasons: array<string, int>}
     */
    private function migrateBalances(bool $dryRun): array
    {
        $stats = [
            'read' => 0,
            'inserted' => 0,
            'skipped_existing' => 0,
            'skipped' => 0,
            'skipped_reasons' => [],
        ];

        $typeIds = LeaveType::query()->pluck('id', 'code')->all();
        $rows = DB::connection('formsdb')->table('leave_confirmation')->orderBy('lc_id')->get();

        foreach ($rows as $row) {
            $empId = (int) $row->lc_eid;

            foreach (LegacyLeaveRowMapper::BALANCE_TYPE_MAP as $column => $code) {
                $stats['read']++;
                $amount = number_format((float) ($row->{$column} ?? 0), 2, '.', '');

                if (bccomp($amount, '0', 2) < 0) {
                    $stats['skipped']++;
                    $stats['skipped_reasons']['negative balance'] =
                        ($stats['skipped_reasons']['negative balance'] ?? 0) + 1;

                    continue;
                }

                // Zero credits: still create an empty row only when missing, via applyInitialBalance(0)?
                // Skip zeros to avoid noise — employee with 0 VL doesn't need a row.
                if (bccomp($amount, '0', 2) === 0) {
                    $stats['skipped']++;
                    $stats['skipped_reasons']['zero balance'] =
                        ($stats['skipped_reasons']['zero balance'] ?? 0) + 1;

                    continue;
                }

                $typeId = $typeIds[$code] ?? null;
                if ($typeId === null) {
                    $stats['skipped']++;
                    $stats['skipped_reasons']['missing leave_type'] =
                        ($stats['skipped_reasons']['missing leave_type'] ?? 0) + 1;

                    continue;
                }

                $existing = LeaveBalance::query()
                    ->where('emp_id', $empId)
                    ->where('leave_type_id', $typeId)
                    ->first();

                if (
                    $existing !== null
                    && (
                        bccomp((string) $existing->available, '0', 2) !== 0
                        || bccomp((string) $existing->reserved, '0', 2) !== 0
                    )
                ) {
                    $stats['skipped_existing']++;

                    continue;
                }

                if ($dryRun) {
                    $stats['inserted']++;

                    continue;
                }

                try {
                    $this->balanceService->applyInitialBalance(
                        $empId,
                        $typeId,
                        $amount,
                        LegacyLeaveRowMapper::REMARKS_PREFIX,
                    );
                    $stats['inserted']++;
                } catch (Throwable $e) {
                    $stats['skipped']++;
                    $key = 'balance error: '.$e->getMessage();
                    $stats['skipped_reasons'][$key] = ($stats['skipped_reasons'][$key] ?? 0) + 1;
                }
            }
        }

        return $stats;
    }

    private function clearPreviousImport(bool $leaves, bool $balances): void
    {
        if ($leaves) {
            LeaveInfo::query()
                ->with('leaveType')
                ->whereNotNull('legacy_leave_id')
                ->where('status', LeaveStatus::PendingApproval->value)
                ->orderBy('id')
                ->chunkById(100, function ($rows) {
                    foreach ($rows as $leave) {
                        try {
                            DB::transaction(function () use ($leave) {
                                $code = strtoupper((string) ($leave->leaveType?->code ?? ''));
                                if ($code === '') {
                                    return;
                                }
                                if (LeaveTypeRules::usesCredits($code)) {
                                    $this->balanceService->releaseReservation($leave);
                                }
                                if (LeaveTypeRules::grantsCreditsOnSubmit($code)) {
                                    $this->balanceService->reverseParentalEntitlement($leave);
                                }
                            });
                        } catch (Throwable) {
                            // Still delete the leave even if balance undo fails.
                        }
                    }
                });

            LeaveInfo::query()
                ->whereNotNull('legacy_leave_id')
                ->with('attachments')
                ->orderBy('id')
                ->chunkById(200, function ($rows) {
                    foreach ($rows as $leave) {
                        foreach ($leave->attachments as $attachment) {
                            $this->attachmentService->delete($attachment->path);
                        }
                        $leave->delete();
                    }
                });
        }

        if ($balances) {
            $txnIds = LeaveBalanceTransaction::query()
                ->where('reason', 'initial_balance')
                ->where('remarks', LegacyLeaveRowMapper::REMARKS_PREFIX)
                ->pluck('id');

            $pairs = LeaveBalanceTransaction::query()
                ->whereIn('id', $txnIds)
                ->get(['emp_id', 'leave_type_id']);

            LeaveBalanceTransaction::query()->whereIn('id', $txnIds)->delete();

            foreach ($pairs as $pair) {
                $remaining = LeaveBalanceTransaction::query()
                    ->where('emp_id', $pair->emp_id)
                    ->where('leave_type_id', $pair->leave_type_id)
                    ->exists();

                if (! $remaining) {
                    LeaveBalance::query()
                        ->where('emp_id', $pair->emp_id)
                        ->where('leave_type_id', $pair->leave_type_id)
                        ->delete();
                } else {
                    LeaveBalance::query()
                        ->where('emp_id', $pair->emp_id)
                        ->where('leave_type_id', $pair->leave_type_id)
                        ->update([
                            'available' => '0.00',
                            'reserved' => '0.00',
                        ]);
                }
            }
        }
    }

    /**
     * @param  list<int|string>  $leaveIds
     * @return array<int, list<array{emp_id: int, accept: int, remarks: ?string, time: ?string}>>
     */
    private function loadAccepts($legacy, array $leaveIds): array
    {
        if ($leaveIds === []) {
            return [];
        }

        $rows = $legacy->table('leave_accept')
            ->whereIn('fldLeaveID', $leaveIds)
            ->orderBy('fldID')
            ->get(['fldLeaveID', 'fldEmployeeNum', 'fldAccept', 'fldRemarks', 'fldTime']);

        $grouped = [];
        foreach ($rows as $row) {
            $leaveId = (int) $row->fldLeaveID;
            $grouped[$leaveId][] = [
                'emp_id' => (int) $row->fldEmployeeNum,
                'accept' => (int) $row->fldAccept,
                'remarks' => $row->fldRemarks,
                'time' => $row->fldTime,
            ];
        }

        return $grouped;
    }

    private function skipBucket(string $message): string
    {
        if (str_contains($message, 'non-positive duration')) {
            return 'non-positive duration';
        }
        if (str_contains($message, 'invalid dates')) {
            return 'invalid dates';
        }
        if (str_contains($message, 'unknown leave type')) {
            return 'unknown leave type';
        }
        if (str_contains($message, 'unknown day type')) {
            return 'unknown day type';
        }
        if (str_contains($message, 'no HR remarks and no leave_accept')) {
            return 'no decision';
        }
        if (str_contains($message, 'mixed leave_accept')) {
            return 'mixed leave_accept';
        }

        return 'other';
    }

    private function assertLegacyConnection(): void
    {
        try {
            DB::connection('formsdb')->select('SELECT 1 AS ok');
        } catch (Throwable $e) {
            throw new RuntimeException(
                'Cannot connect to formsdb (LEGACY_FORMS_DB_*). '.$e->getMessage(),
                0,
                $e,
            );
        }

        if (! DB::connection('formsdb')->getSchemaBuilder()->hasTable('leave_info')) {
            throw new RuntimeException('formsdb.leave_info table was not found.');
        }
    }

    private function assertLeaveTypesSeeded(): void
    {
        $needed = ['VL', 'SL', 'EL', 'ML', 'PL', 'PTB', 'LL'];
        $have = LeaveType::query()->whereIn('code', $needed)->pluck('code')->all();
        $missing = array_values(array_diff($needed, $have));

        if ($missing !== []) {
            throw new RuntimeException(
                'leave_type is missing codes: '.implode(', ', $missing).'. Run LeaveTypeSeeder first.'
            );
        }
    }
}
