<?php

namespace App\Services\Leave;

use App\Enums\Leave\ActivityType;
use App\Enums\Leave\BalanceTransactionReason;
use App\Models\Kdtph\Employee;
use App\Models\Leave\LeaveActivityHistory;
use App\Models\Leave\LeaveBalanceTransaction;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Read-only Activity Log for the viewer: leaves they are on, plus credit
 * grants they received (batch distribution and solo manual adjust).
 */
class LeaveActivityListService
{
    /**
     * Paginate activity rows for this employee.
     *
     * Each model is enriched with transient attributes used by LeaveActivityResource:
     * performed_by_name, balance_impact, ledger.
     */
    public function paginateForEmployee(
        int $empId,
        ?string $leaveTypeCode = null,
        ?ActivityType $activityType = null,
        int $perPage = 15,
        string $sort = 'desc',
    ): LengthAwarePaginator {
        $direction = $sort === 'asc' ? 'asc' : 'desc';

        $query = LeaveActivityHistory::query()
            ->with(['leaveInfo.leaveType', 'leaveInfo.conversion.toLeaveType', 'leaveType']);

        $this->applyEmployeeScope($query, $empId, $leaveTypeCode);

        $paginator = $query
            ->when($activityType !== null, fn ($q) => $q->where('activity_type', $activityType->value))
            ->orderBy('created_at', $direction)
            ->orderBy('id', $direction)
            ->paginate($perPage);

        $this->enrichPage($paginator->getCollection(), $empId);

        return $paginator;
    }

    /**
     * Leaves the viewer is on, batch credit rows they received, or a solo
     * credits_adjusted row where they are the subject.
     */
    private function applyEmployeeScope(Builder $query, int $empId, ?string $leaveTypeCode): void
    {
        $query->where(function (Builder $outer) use ($empId, $leaveTypeCode): void {
            $outer->whereHas('leaveInfo', function (Builder $leave) use ($empId, $leaveTypeCode): void {
                $leave->where('emp_id', $empId)
                    ->when($leaveTypeCode !== null, function (Builder $leave) use ($leaveTypeCode): void {
                        $leave->whereHas('leaveType', fn (Builder $type) => $type->where('code', $leaveTypeCode));
                    });
            })->orWhere(function (Builder $credit) use ($empId, $leaveTypeCode): void {
                $credit->whereNull('leave_id')
                    ->where(function (Builder $who) use ($empId): void {
                        $who->where('subject_emp_id', $empId)
                            ->orWhereHas('recipients', fn (Builder $recipients) => $recipients->where('emp_id', $empId));
                    })
                    ->when($leaveTypeCode !== null, function (Builder $credit) use ($leaveTypeCode): void {
                        $credit->whereHas('leaveType', fn (Builder $type) => $type->where('code', $leaveTypeCode));
                    });
            });
        });
    }

    /**
     * @param  Collection<int, LeaveActivityHistory>  $activities
     */
    private function enrichPage(Collection $activities, int $empId): void
    {
        if ($activities->isEmpty()) {
            return;
        }

        $leaveIds = $activities->pluck('leave_id')->filter()->unique()->values();
        $impactsByActivityId = $this->matchLedgerToActivities($leaveIds)
            + $this->matchCreditLedgerToActivities($empId, $activities);
        $employeesById = $this->employeesById(
            $activities->pluck('performed_by_emp_id')->unique()->filter()->values()->all()
        );

        foreach ($activities as $activity) {
            $matched = $impactsByActivityId[$activity->id] ?? collect();

            $activity->setAttribute(
                'performed_by_name',
                $employeesById->get($activity->performed_by_emp_id)?->fullName(),
            );
            $activity->setAttribute('ledger', $matched->values()->all());
            $activity->setAttribute(
                'balance_impact',
                $this->formatBalanceImpact($matched),
            );
        }
    }

    /**
     * Walk full activity + ledger timelines per leave so page boundaries
     * do not mis-pair rows (e.g. modified release/reserve).
     *
     * @param  Collection<int, int>  $leaveIds
     * @return array<int, Collection<int, LeaveBalanceTransaction>>
     */
    private function matchLedgerToActivities(Collection $leaveIds): array
    {
        if ($leaveIds->isEmpty()) {
            return [];
        }

        $allActivities = LeaveActivityHistory::query()
            ->whereIn('leave_id', $leaveIds)
            ->orderBy('created_at')
            ->orderBy('id')
            ->get()
            ->groupBy('leave_id');

        $allTransactions = LeaveBalanceTransaction::query()
            ->with('leaveType')
            ->whereIn('leave_id', $leaveIds)
            ->orderBy('created_at')
            ->orderBy('id')
            ->get()
            ->groupBy('leave_id');

        $matched = [];

        foreach ($leaveIds as $leaveId) {
            $queue = $allTransactions->get($leaveId, collect())->values()->all();
            $used = array_fill(0, count($queue), false);

            foreach ($allActivities->get($leaveId, collect()) as $activity) {
                $picked = collect();

                foreach ($this->ledgerReasonsFor($activity->activity_type) as $reason) {
                    foreach ($queue as $index => $transaction) {
                        if ($used[$index]) {
                            continue;
                        }

                        if ($transaction->reason === $reason) {
                            $used[$index] = true;
                            $picked->push($transaction);
                            break;
                        }
                    }
                }

                $matched[$activity->id] = $picked;
            }
        }

        return $matched;
    }

    /**
     * Pair this employee's credit-grant ledger rows to activity history.
     * Walks the full timeline so pagination does not skip a prior grant.
     *
     * @param  Collection<int, LeaveActivityHistory>  $activities
     * @return array<int, Collection<int, LeaveBalanceTransaction>>
     */
    private function matchCreditLedgerToActivities(int $empId, Collection $activities): array
    {
        $hasCreditOnPage = $activities->contains(
            fn (LeaveActivityHistory $activity): bool => $activity->activity_type?->isCreditGrant() ?? false,
        );

        if (! $hasCreditOnPage) {
            return [];
        }

        $allCreditActivities = LeaveActivityHistory::query()
            ->whereNull('leave_id')
            ->where(function (Builder $who) use ($empId): void {
                $who->where('subject_emp_id', $empId)
                    ->orWhereHas('recipients', fn (Builder $recipients) => $recipients->where('emp_id', $empId));
            })
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();

        $queue = LeaveBalanceTransaction::query()
            ->with('leaveType')
            ->where('emp_id', $empId)
            ->whereNull('leave_id')
            ->whereIn('reason', [
                BalanceTransactionReason::AnnualEntitlement->value,
                BalanceTransactionReason::RegularizationEntitlement->value,
                BalanceTransactionReason::ContractualEntitlement->value,
                BalanceTransactionReason::ManualAdjustment->value,
            ])
            ->orderBy('created_at')
            ->orderBy('id')
            ->get()
            ->values()
            ->all();

        $used = array_fill(0, count($queue), false);
        $matched = [];

        foreach ($allCreditActivities as $activity) {
            $picked = collect();

            foreach ($this->ledgerReasonsFor($activity->activity_type) as $reason) {
                foreach ($queue as $index => $transaction) {
                    if ($used[$index]) {
                        continue;
                    }

                    if ($transaction->reason !== $reason) {
                        continue;
                    }

                    if ((int) $transaction->leave_type_id !== (int) $activity->leave_type_id) {
                        continue;
                    }

                    $used[$index] = true;
                    $picked->push($transaction);
                    break;
                }
            }

            $matched[$activity->id] = $picked;
        }

        return $matched;
    }

    /**
     * @return list<BalanceTransactionReason>
     */
    private function ledgerReasonsFor(ActivityType $type): array
    {
        return match ($type) {
            ActivityType::Submitted => [BalanceTransactionReason::LeaveReserved],
            ActivityType::Modified => [
                BalanceTransactionReason::LeaveReservationReleased,
                BalanceTransactionReason::LeaveReserved,
            ],
            ActivityType::Cancelled => [
                BalanceTransactionReason::LeaveReservationReleased,
                BalanceTransactionReason::LeaveReversed,
            ],
            ActivityType::Rejected => [
                BalanceTransactionReason::LeaveReservationReleased,
            ],
            ActivityType::LeaveConfirmed => [BalanceTransactionReason::LeaveConfirmed],
            ActivityType::ConversionApproved => [
                BalanceTransactionReason::VlToSlRestore,
                BalanceTransactionReason::VlToSlDeduct,
                BalanceTransactionReason::VlToElRestore,
                BalanceTransactionReason::VlToElDeduct,
            ],
            ActivityType::AnnualCreditsDistributed => [BalanceTransactionReason::AnnualEntitlement],
            ActivityType::RegularizationCreditsDistributed => [BalanceTransactionReason::RegularizationEntitlement],
            ActivityType::ContractualCreditsDistributed => [BalanceTransactionReason::ContractualEntitlement],
            ActivityType::CreditsAdjusted => [BalanceTransactionReason::ManualAdjustment],
            default => [],
        };
    }

    /**
     * @param  Collection<int, LeaveBalanceTransaction>  $transactions
     */
    private function formatBalanceImpact(Collection $transactions): string
    {
        if ($transactions->isEmpty()) {
            return 'No change';
        }

        return $transactions
            ->map(fn (LeaveBalanceTransaction $tx) => $this->formatLedgerRow($tx))
            ->filter()
            ->implode('; ');
    }

    private function formatLedgerRow(LeaveBalanceTransaction $tx): string
    {
        $code = $tx->leaveType?->code ?? '?';

        return match ($tx->reason) {
            BalanceTransactionReason::LeaveReserved => sprintf(
                '%s %s reserved',
                $this->formatDays($tx->reserved_delta),
                $code,
            ),
            BalanceTransactionReason::LeaveConfirmed => sprintf(
                '%s %s confirmed',
                $this->formatDays(bcsub('0', (string) $tx->reserved_delta, 2)),
                $code,
            ),
            BalanceTransactionReason::LeaveReservationReleased => sprintf(
                '%s %s released',
                $this->formatDays($tx->available_delta),
                $code,
            ),
            BalanceTransactionReason::LeaveReversed,
            BalanceTransactionReason::VlToSlRestore,
            BalanceTransactionReason::VlToElRestore => sprintf(
                '%s %s restored',
                $this->formatDays($tx->available_delta),
                $code,
            ),
            BalanceTransactionReason::VlToSlDeduct,
            BalanceTransactionReason::VlToElDeduct => sprintf(
                '%s %s deducted',
                $this->formatDays(bcsub('0', (string) $tx->available_delta, 2)),
                $code,
            ),
            BalanceTransactionReason::AnnualEntitlement,
            BalanceTransactionReason::RegularizationEntitlement,
            BalanceTransactionReason::ContractualEntitlement => sprintf(
                '%s %s credited',
                $this->formatDays($tx->available_delta),
                $code,
            ),
            BalanceTransactionReason::ManualAdjustment => bccomp((string) $tx->available_delta, '0', 2) < 0
                ? sprintf(
                    '%s %s deducted',
                    $this->formatDays($tx->available_delta),
                    $code,
                )
                : sprintf(
                    '%s %s credited',
                    $this->formatDays($tx->available_delta),
                    $code,
                ),
            default => 'No change',
        };
    }

    /**
     * Prefer UI-friendly amounts: "3.0", "1.5", "0.5".
     */
    private function formatDays(string $amount): string
    {
        $abs = ltrim((string) $amount, '+-');

        if (str_contains($abs, '.')) {
            $abs = rtrim(rtrim($abs, '0'), '.');
        }

        if (! str_contains($abs, '.')) {
            $abs .= '.0';
        }

        return $abs;
    }

    /**
     * @param  list<int>  $ids
     * @return Collection<int, Employee>
     */
    private function employeesById(array $ids): Collection
    {
        if ($ids === []) {
            return collect();
        }

        return Employee::query()
            ->whereIn('id', $ids)
            ->get()
            ->keyBy('id');
    }
}
