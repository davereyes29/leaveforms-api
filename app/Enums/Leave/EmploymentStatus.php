<?php

namespace App\Enums\Leave;

enum EmploymentStatus: string
{
    case Regular = 'regular';
    case Probationary = 'probationary';
    case Contractual = 'contractual';

    public static function fromDesignationId(int $designationId): self
    {
        $probationary = self::designationIds('probationary_designation_ids');
        $contractual = self::designationIds('contractual_designation_ids');

        if (in_array($designationId, $probationary, true)) {
            return self::Probationary;
        }

        if (in_array($designationId, $contractual, true)) {
            return self::Contractual;
        }

        return self::Regular;
    }

    /**
     * @return list<int>
     */
    public static function designationIds(string $configKey): array
    {
        $ids = config('leave.employment.'.$configKey, []);

        if (! is_array($ids)) {
            return [];
        }

        return array_values(array_map('intval', $ids));
    }
}
