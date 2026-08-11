<?php

namespace App\Services\Leave;

use App\Enums\Leave\ActivityType;
use App\Enums\Leave\BalanceTransactionReason;
use App\Models\Kdtph\Employee;
use App\Models\Leave\LeaveActivityHistory;
use App\Models\Leave\LeaveBalanceTransaction;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

/**
 * Read-only Activity Log feed for the person on leave (leave_info.emp_id).
 */
class LeaveActivityListService
{
    /**
     * Paginate activity rows for leaves belonging to this employee.
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

        $paginator = LeaveActivityHistory::query()
            ->with(['leaveInfo.leaveType'])
            ->whereHas('leaveInfo', function ($query) use ($empId, $leaveTypeCode) {
                $query->where('emp_id', $empId)
                    ->when($leaveTypeCode !== null, function ($query) use ($leaveTypeCode) {
                        $query->whereHas('leaveType', fn ($q) => $q->where('code', $leaveTypeCode));
                    });
            })
            ->when($activityType !== null, fn ($query) => $query->where('activity_type', $activityType->value))
            ->orderBy('created_at', $direction)
            ->orderBy('id', $direction)
            ->paginate($perPage);

        $this->enrichPage($paginator->getCollection());

        return $paginator;
    }

    /**
     * @param  Collection<int, LeaveActivityHistory>  $activities
     */
    private function enrichPage(Collection $activities): void
    {
        if ($activities->isEmpty()) {
            return;
        }

        $leaveIds = $activities->pluck('leave_id')->unique()->values();
        $impactsByActivityId = $this->matchLedgerToActivities($leaveIds);
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
            ActivityType::Cancelled, ActivityType::Rejected => [
                BalanceTransactionReason::LeaveReservationReleased,
            ],
            ActivityType::LeaveConfirmed => [BalanceTransactionReason::LeaveConfirmed],
            ActivityType::ConversionApproved => [
                BalanceTransactionReason::VlToSlRestore,
                BalanceTransactionReason::VlToSlDeduct,
            ],
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
            BalanceTransactionReason::VlToSlRestore => sprintf(
                '%s %s restored',
                $this->formatDays($tx->available_delta),
                $code,
            ),
            BalanceTransactionReason::VlToSlDeduct => sprintf(
                '%s %s deducted',
                $this->formatDays(bcsub('0', (string) $tx->available_delta, 2)),
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
