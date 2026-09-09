<?php

namespace App\Services\Leave;

use App\Enums\Leave\BalanceTransactionReason;
use App\Models\Leave\LeaveBalance;
use App\Models\Leave\LeaveBalanceTransaction;
use App\Models\Leave\LeaveConversion;
use App\Models\Leave\LeaveInfo;
use App\Models\Leave\LeaveType;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Reads and updates leave credit balances for an employee.
 *
 * All bucket changes must go through this service so every update
 * also writes a leave_balance_transaction ledger row.
 */
class LeaveBalanceService
{
    /**
     * Return one row per leave type, merging lookup data with balance rows.
     *
     * @param  ?string  $code  Optional filter, e.g. "VL" — returns only that type.
     *
     * If the employee has no leave_balance row yet for a type, available and
     * reserved default to 0 — that is normal for a new employee.
     */
    public function getForEmployee(int $empId, ?string $code = null): Collection
    {
        $typesQuery = LeaveType::query()->orderBy('id');

        if ($code !== null) {
            $typesQuery->where('code', $code);
        }

        $types = $typesQuery->get();

        if ($code === null) {
            $types = $types->reject(
                fn (LeaveType $type): bool => ! LeaveTypeRules::usesCredits((string) $type->code),
            )->values();
        }

        $balances = LeaveBalance::query()
            ->where('emp_id', $empId)
            ->when($code !== null, function ($query) use ($types) {
                $query->whereIn('leave_type_id', $types->pluck('id'));
            })
            ->get()
            ->keyBy('leave_type_id');

        return $types->map(function (LeaveType $type) use ($balances) {
            $row = $balances->get($type->id);

            return [
                'leave_type_id' => $type->id,
                'code' => $type->code,
                'name' => $type->type,
                'available' => $row?->available ?? '0.00',
                'reserved' => $row?->reserved ?? '0.00',
            ];
        });
    }

    /**
     * Grant opening credits — used for migration or local dev seeding.
     *
     * Only allowed when both available and reserved are 0.
     */
    public function applyInitialBalance(
        int $empId,
        int $leaveTypeId,
        string $amount,
        ?string $remarks = null,
    ): LeaveBalance {
        return DB::transaction(function () use ($empId, $leaveTypeId, $amount, $remarks) {
            $balance = $this->lockOrCreateBalance($empId, $leaveTypeId);
            $this->assertBalanceEmpty($balance);

            return $this->recordChange(
                $balance,
                $amount,
                '0.00',
                BalanceTransactionReason::InitialBalance,
                $remarks,
            );
        });
    }

    /**
     * Yearly VL or SL grant. Available only; reserved unchanged.
     *
     * Must be called inside an outer DB::transaction (batch distribution).
     */
    public function applyAnnualEntitlement(
        int $empId,
        int $leaveTypeId,
        string $amount,
        ?string $remarks = null,
    ): LeaveBalance {
        $balance = $this->lockOrCreateBalance($empId, $leaveTypeId);

        return $this->recordChange(
            $balance,
            $amount,
            '0.00',
            BalanceTransactionReason::AnnualEntitlement,
            $remarks,
        );
    }

    /**
     * One-time mid-year grant (VL/SL prorated, or flat PTB).
     * Available only; reserved unchanged.
     *
     * Must be called inside an outer DB::transaction (batch distribution).
     */
    public function applyRegularizationEntitlement(
        int $empId,
        int $leaveTypeId,
        string $amount,
        ?string $remarks = null,
    ): LeaveBalance {
        $balance = $this->lockOrCreateBalance($empId, $leaveTypeId);

        return $this->recordChange(
            $balance,
            $amount,
            '0.00',
            BalanceTransactionReason::RegularizationEntitlement,
            $remarks,
        );
    }

    /**
     * Admin-typed VL/SL grant for contractual employees.
     * Available only; reserved unchanged.
     *
     * Must be called inside an outer DB::transaction (batch distribution).
     */
    public function applyContractualEntitlement(
        int $empId,
        int $leaveTypeId,
        string $amount,
        ?string $remarks = null,
    ): LeaveBalance {
        $balance = $this->lockOrCreateBalance($empId, $leaveTypeId);

        return $this->recordChange(
            $balance,
            $amount,
            '0.00',
            BalanceTransactionReason::ContractualEntitlement,
            $remarks,
        );
    }

    /**
     * Add or subtract credits — used when balance already exists.
     */
    public function applyManualAdjustment(
        int $empId,
        int $leaveTypeId,
        string $availableDelta,
        ?string $remarks = null,
    ): LeaveBalance {
        return DB::transaction(function () use ($empId, $leaveTypeId, $availableDelta, $remarks) {
            $balance = $this->lockOrCreateBalance($empId, $leaveTypeId);

            return $this->recordChange(
                $balance,
                $availableDelta,
                '0.00',
                BalanceTransactionReason::ManualAdjustment,
                $remarks,
            );
        });
    }

    /**
     * Grant ML/PL credits when the request is filed (not a yearly entitlement).
     *
     * Must be called inside an outer DB::transaction, before reservePaidDays.
     */
    public function grantParentalEntitlement(LeaveInfo $leave): void
    {
        $amount = (string) $leave->paid_days;

        if (bccomp($amount, '0', 2) <= 0) {
            return;
        }

        $balance = $this->lockOrCreateBalance($leave->emp_id, $leave->leave_type_id);
        $leave->loadMissing('leaveType');
        $label = $leave->leaveType?->type ?? 'Parental leave';

        $this->recordChange(
            $balance,
            $amount,
            '0.00',
            BalanceTransactionReason::ParentalEntitlement,
            $label.' granted on request',
            $leave->id,
        );
    }

    /**
     * Reverse the on-submit ML/PL grant after reservation release or confirmed undo.
     *
     * Must be called inside an outer DB::transaction, after releaseReservation
     * or reverseConfirmedLeave.
     */
    public function reverseParentalEntitlement(LeaveInfo $leave, ?string $remarks = null): void
    {
        $amount = (string) $leave->paid_days;

        if (bccomp($amount, '0', 2) <= 0) {
            return;
        }

        $balance = $this->lockOrCreateBalance($leave->emp_id, $leave->leave_type_id);

        $this->recordChange(
            $balance,
            bcsub('0', $amount, 2),
            '0.00',
            BalanceTransactionReason::ParentalEntitlementReversed,
            $remarks,
            $leave->id,
        );
    }

    /**
     * Hold paid credits for a pending leave request.
     *
     * Must be called inside an outer DB::transaction (e.g. from LeaveSubmissionService).
     */
    public function reservePaidDays(LeaveInfo $leave): void
    {
        $paidDays = (string) $leave->paid_days;

        if (bccomp($paidDays, '0', 2) <= 0) {
            return;
        }

        $balance = $this->lockOrCreateBalance($leave->emp_id, $leave->leave_type_id);

        $this->recordChange(
            $balance,
            bcsub('0', $paidDays, 2),
            $paidDays,
            BalanceTransactionReason::LeaveReserved,
            null,
            $leave->id,
        );
    }

    /**
     * Release reserved paid credits after reject, cancel, or edit.
     *
     * Must be called inside an outer DB::transaction.
     */
    public function releaseReservation(LeaveInfo $leave, ?string $remarks = null): void
    {
        $paidDays = (string) $leave->paid_days;

        if (bccomp($paidDays, '0', 2) <= 0) {
            return;
        }

        $balance = $this->lockOrCreateBalance($leave->emp_id, $leave->leave_type_id);

        $this->recordChange(
            $balance,
            $paidDays,
            bcsub('0', $paidDays, 2),
            BalanceTransactionReason::LeaveReservationReleased,
            $remarks,
            $leave->id,
        );
    }

    /**
     * Restore paid credits after admin cancels a confirmed leave.
     *
     * Reserved is already 0 after confirm; days return to available.
     * Must be called inside an outer DB::transaction.
     */
    public function reverseConfirmedLeave(LeaveInfo $leave, ?string $remarks = null): void
    {
        $paidDays = (string) $leave->paid_days;

        if (bccomp($paidDays, '0', 2) <= 0) {
            return;
        }

        $balance = $this->lockOrCreateBalance($leave->emp_id, $leave->leave_type_id);

        $this->recordChange(
            $balance,
            $paidDays,
            '0.00',
            BalanceTransactionReason::LeaveReversed,
            $remarks,
            $leave->id,
        );
    }

    /**
     * Finalize reserved paid credits when all approvers have approved.
     *
     * Must be called inside an outer DB::transaction.
     */
    public function confirmPaidDays(LeaveInfo $leave): void
    {
        $paidDays = (string) $leave->paid_days;

        if (bccomp($paidDays, '0', 2) <= 0) {
            return;
        }

        $balance = $this->lockOrCreateBalance($leave->emp_id, $leave->leave_type_id);

        $this->recordChange(
            $balance,
            '0.00',
            bcsub('0', $paidDays, 2),
            BalanceTransactionReason::LeaveConfirmed,
            null,
            $leave->id,
        );
    }

    /**
     * After admin approves conversion: restore VL paid days, deduct target type.
     *
     * VL → SL deducts paid_days from SL (LWOP VL with paid_days 0 is a no-op).
     * VL → EL deducts duration from EL (LWOP VL still consumes EL; remaining EL
     * must cover duration). Must be called inside an outer DB::transaction.
     */
    public function applyConversion(LeaveConversion $conversion): void
    {
        $conversion->loadMissing('toLeaveType');

        $leave = $conversion->leaveInfo()
            ->lockForUpdate()
            ->firstOrFail();

        if (strtoupper((string) ($conversion->toLeaveType?->code ?? '')) === 'EL') {
            $this->applyVlToElConversion($leave, $conversion);

            return;
        }

        $this->applyVlToSlConversion($leave, $conversion);
    }

    private function applyVlToSlConversion(LeaveInfo $leave, LeaveConversion $conversion): void
    {
        $paidDays = (string) $leave->paid_days;

        if (bccomp($paidDays, '0', 2) <= 0) {
            return;
        }

        $vlBalance = $this->lockOrCreateBalance($leave->emp_id, $conversion->from_leave_type_id);

        $this->recordChange(
            $vlBalance,
            $paidDays,
            '0.00',
            BalanceTransactionReason::VlToSlRestore,
            null,
            $leave->id,
        );

        $slBalance = $this->lockOrCreateBalance($leave->emp_id, $conversion->to_leave_type_id);

        $this->recordChange(
            $slBalance,
            bcsub('0', $paidDays, 2),
            '0.00',
            BalanceTransactionReason::VlToSlDeduct,
            null,
            $leave->id,
        );
    }

    private function applyVlToElConversion(LeaveInfo $leave, LeaveConversion $conversion): void
    {
        $duration = bcadd((string) $leave->duration, '0', 2);
        $paidDays = bcadd((string) $leave->paid_days, '0', 2);

        $elBalance = $this->lockOrCreateBalance($leave->emp_id, $conversion->to_leave_type_id);

        if (bccomp((string) $elBalance->available, $duration, 2) < 0) {
            throw new RuntimeException(
                "Remaining Emergency Leave credits ({$elBalance->available}) are less than this leave duration ({$duration})."
            );
        }

        if (bccomp($paidDays, '0', 2) > 0) {
            $vlBalance = $this->lockOrCreateBalance($leave->emp_id, $conversion->from_leave_type_id);

            $this->recordChange(
                $vlBalance,
                $paidDays,
                '0.00',
                BalanceTransactionReason::VlToElRestore,
                null,
                $leave->id,
            );
        }

        $this->recordChange(
            $elBalance,
            bcsub('0', $duration, 2),
            '0.00',
            BalanceTransactionReason::VlToElDeduct,
            null,
            $leave->id,
        );
    }

    /**
     * Credits available when editing a pending leave (includes this leave's current reserve).
     */
    public function availableForEdit(LeaveInfo $leave): string
    {
        return bcadd(
            $this->availableFor($leave->emp_id, $leave->leave_type_id),
            (string) $leave->paid_days,
            2,
        );
    }

    /**
     * Read available credits for one leave type (defaults to 0 when no row exists).
     */
    public function availableFor(int $empId, int $leaveTypeId): string
    {
        $available = LeaveBalance::query()
            ->where('emp_id', $empId)
            ->where('leave_type_id', $leaveTypeId)
            ->value('available');

        return $available !== null ? (string) $available : '0.00';
    }

    public function lockOrCreateBalance(int $empId, int $leaveTypeId): LeaveBalance
    {
        $balance = LeaveBalance::query()
            ->where('emp_id', $empId)
            ->where('leave_type_id', $leaveTypeId)
            ->lockForUpdate()
            ->first();

        if ($balance !== null) {
            return $balance;
        }

        return LeaveBalance::query()->create([
            'emp_id' => $empId,
            'leave_type_id' => $leaveTypeId,
            'available' => '0.00',
            'reserved' => '0.00',
        ]);
    }

    private function assertBalanceEmpty(LeaveBalance $balance): void
    {
        if (
            bccomp((string) $balance->available, '0', 2) !== 0
            || bccomp((string) $balance->reserved, '0', 2) !== 0
        ) {
            throw new RuntimeException(
                'Balance already has credits. Use --force to add more via manual_adjustment.'
            );
        }
    }

    private function recordChange(
        LeaveBalance $balance,
        string $availableDelta,
        string $reservedDelta,
        BalanceTransactionReason $reason,
        ?string $remarks = null,
        ?int $leaveId = null,
    ): LeaveBalance {
        $availableAfter = bcadd((string) $balance->available, $availableDelta, 2);
        $reservedAfter = bcadd((string) $balance->reserved, $reservedDelta, 2);

        if (bccomp($availableAfter, '0', 2) < 0 || bccomp($reservedAfter, '0', 2) < 0) {
            throw new RuntimeException('Balance cannot go negative.');
        }

        $balance->update([
            'available' => $availableAfter,
            'reserved' => $reservedAfter,
        ]);

        LeaveBalanceTransaction::query()->create([
            'emp_id' => $balance->emp_id,
            'leave_id' => $leaveId,
            'leave_type_id' => $balance->leave_type_id,
            'available_delta' => $availableDelta,
            'reserved_delta' => $reservedDelta,
            'available_after' => $availableAfter,
            'reserved_after' => $reservedAfter,
            'reason' => $reason,
            'remarks' => $remarks,
        ]);

        return $balance->fresh();
    }
}
