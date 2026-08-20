<?php

namespace App\Http\Resources;

use App\Models\Kdtph\Employee;

/**
 * Shared employee nest for leave detail and approver list rows.
 */
final class EmployeeNest
{
    /**
     * @return array{id: int, employee_number: string, name: string, group: ?array{id: int, abbreviation: string|null, name: string|null}, department: ?array{id: int, abbreviation: string|null, name: string|null}}|null
     */
    public static function from(?Employee $employee): ?array
    {
        if ($employee === null) {
            return null;
        }

        $group = $employee->group;
        $department = $group?->department;

        return [
            'id' => $employee->id,
            'employee_number' => (string) $employee->id,
            'name' => $employee->fullName(),
            'group' => $group === null ? null : [
                'id' => $group->id,
                'abbreviation' => $group->abbreviation,
                'name' => $group->name,
            ],
            'department' => $department === null ? null : [
                'id' => $department->id,
                'abbreviation' => $department->abbreviation,
                'name' => $department->name,
            ],
        ];
    }

    /**
     * Slim nest (id / employee_number / name) for filers and similar actors.
     *
     * @return array{id: int, employee_number: string, name: string}|null
     */
    public static function summary(?Employee $employee): ?array
    {
        if ($employee === null) {
            return null;
        }

        return [
            'id' => $employee->id,
            'employee_number' => (string) $employee->id,
            'name' => $employee->fullName(),
        ];
    }
}
