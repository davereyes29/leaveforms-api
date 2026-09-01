<?php

namespace App\Services\Kdtph;

use App\Enums\Leave\EmploymentStatus;
use App\Models\Kdtph\Designation;
use App\Models\Kdtph\Employee;
use App\Models\Kdtph\EmpProf;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Resolves the current job title from kdtphdb.emp_prof.fldDesig
 * (acronym → designation_list), with employee_list.designation as fallback.
 */
class EmployeeDesignationResolver
{
    /**
     * @var array{regular: list<int>, probationary: list<int>, contractual: list<int>, resolved: list<int>}|null
     */
    private ?array $classified = null;

    public function forEmployee(Employee $employee): ?Designation
    {
        if ($employee->relationLoaded('resolvedJobDesignation')) {
            /** @var Designation|null $resolved */
            $resolved = $employee->getRelation('resolvedJobDesignation');

            return $resolved;
        }

        $fromAcronym = Designation::findByAcronym($employee->empProf?->fldDesig);

        if ($fromAcronym !== null) {
            $employee->setRelation('resolvedJobDesignation', $fromAcronym);

            return $fromAcronym;
        }

        $fallback = $this->fallbackDesignation($employee);
        $employee->setRelation('resolvedJobDesignation', $fallback);

        return $fallback;
    }

    /**
     * Batch-load emp_prof + designation_list onto the collection (avoids N+1).
     *
     * @param  Collection<int, Employee>  $employees
     */
    public function hydrate(Collection $employees): void
    {
        if ($employees->isEmpty()) {
            return;
        }

        $ids = $employees
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        $profs = EmpProf::query()
            ->whereIn('fldEmployeeNum', $ids)
            ->get()
            ->keyBy(fn (EmpProf $prof): int => (int) $prof->fldEmployeeNum);

        $byAcronym = Designation::mapByAcronyms(
            $profs->pluck('fldDesig')->filter()->values()->all(),
        );

        $fallbackIds = [];

        foreach ($employees as $employee) {
            $prof = $profs->get((int) $employee->id);
            $employee->setRelation('empProf', $prof);

            $designation = $prof !== null ? $byAcronym->get((string) $prof->fldDesig) : null;

            if ($designation === null && (int) $employee->designation > 0) {
                $fallbackIds[] = (int) $employee->designation;
            }
        }

        $fallbacks = $fallbackIds === []
            ? collect()
            : Designation::query()
                ->whereIn('id', array_values(array_unique($fallbackIds)))
                ->get()
                ->keyBy('id');

        foreach ($employees as $employee) {
            $prof = $employee->empProf;
            $designation = $prof !== null ? $byAcronym->get((string) $prof->fldDesig) : null;

            if ($designation === null && (int) $employee->designation > 0) {
                $designation = $fallbacks->get((int) $employee->designation);
            }

            $employee->setRelation('resolvedJobDesignation', $designation);
        }
    }

    /**
     * @param  Builder<Employee>  $query
     */
    public function applyStatus(Builder $query, EmploymentStatus $status): void
    {
        $classified = $this->classifiedEmployeeIds();
        $table = $query->getModel()->getTable();
        $fromProf = $classified[$status->value];
        $resolvedIds = $classified['resolved'];
        $probationaryIds = EmploymentStatus::designationIds('probationary_designation_ids');
        $contractualIds = EmploymentStatus::designationIds('contractual_designation_ids');
        $excludedIds = array_values(array_unique([...$probationaryIds, ...$contractualIds]));

        $query->where(function (Builder $outer) use (
            $status,
            $fromProf,
            $resolvedIds,
            $table,
            $probationaryIds,
            $contractualIds,
            $excludedIds,
        ): void {
            $outer->whereIn($table.'.id', $this->idsOrNone($fromProf));

            $outer->orWhere(function (Builder $fallback) use (
                $status,
                $resolvedIds,
                $table,
                $probationaryIds,
                $contractualIds,
                $excludedIds,
            ): void {
                $fallback->whereNotIn($table.'.id', $this->idsOrNone($resolvedIds));

                match ($status) {
                    EmploymentStatus::Probationary => $fallback->whereIn(
                        $table.'.designation',
                        $this->idsOrNone($probationaryIds),
                    ),
                    EmploymentStatus::Contractual => $fallback->whereIn(
                        $table.'.designation',
                        $this->idsOrNone($contractualIds),
                    ),
                    EmploymentStatus::Regular => $fallback->whereNotIn(
                        $table.'.designation',
                        $this->idsOrNone($excludedIds),
                    ),
                };
            });
        });
    }

    /**
     * @param  Builder<Employee>  $query
     */
    public function applyNotStatus(Builder $query, EmploymentStatus $excluded): void
    {
        $query->where(function (Builder $outer) use ($excluded): void {
            foreach (EmploymentStatus::cases() as $status) {
                if ($status === $excluded) {
                    continue;
                }

                $outer->orWhere(function (Builder $inner) use ($status): void {
                    $this->applyStatus($inner, $status);
                });
            }
        });
    }

    /**
     * @return array{regular: list<int>, probationary: list<int>, contractual: list<int>, resolved: list<int>}
     */
    private function classifiedEmployeeIds(): array
    {
        if ($this->classified !== null) {
            return $this->classified;
        }

        $probationaryAcronyms = $this->acronymsForIds(
            EmploymentStatus::designationIds('probationary_designation_ids'),
        );
        $contractualAcronyms = $this->acronymsForIds(
            EmploymentStatus::designationIds('contractual_designation_ids'),
        );
        $knownAcronyms = Designation::query()
            ->pluck('acronym')
            ->unique()
            ->filter()
            ->values()
            ->all();

        $regular = [];
        $probationary = [];
        $contractual = [];
        $resolved = [];

        if ($knownAcronyms !== []) {
            $rows = EmpProf::query()
                ->whereIn('fldDesig', $knownAcronyms)
                ->get(['fldEmployeeNum', 'fldDesig']);

            foreach ($rows as $row) {
                $empId = (int) $row->fldEmployeeNum;
                $acronym = (string) $row->fldDesig;
                $resolved[] = $empId;

                if (in_array($acronym, $probationaryAcronyms, true)) {
                    $probationary[] = $empId;
                } elseif (in_array($acronym, $contractualAcronyms, true)) {
                    $contractual[] = $empId;
                } else {
                    $regular[] = $empId;
                }
            }
        }

        return $this->classified = [
            'regular' => $regular,
            'probationary' => $probationary,
            'contractual' => $contractual,
            'resolved' => $resolved,
        ];
    }

    /**
     * @param  list<int>  $ids
     * @return list<string>
     */
    private function acronymsForIds(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return Designation::query()
            ->whereIn('id', $ids)
            ->pluck('acronym')
            ->unique()
            ->filter()
            ->values()
            ->all();
    }

    /**
     * @param  list<int>  $ids
     * @return list<int>
     */
    private function idsOrNone(array $ids): array
    {
        return $ids === [] ? [-1] : $ids;
    }

    private function fallbackDesignation(Employee $employee): ?Designation
    {
        if ($employee->relationLoaded('jobDesignation')) {
            return $employee->jobDesignation;
        }

        if ((int) $employee->designation <= 0) {
            return null;
        }

        return $employee->jobDesignation()->first();
    }
}
