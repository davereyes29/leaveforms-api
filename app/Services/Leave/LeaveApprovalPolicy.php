<?php

namespace App\Services\Leave;

use App\Enums\Leave\RequiredApproverType;
use App\Models\Kdtph\Formspic;

/**
 * Builds leave_approval_requirement rows based on leave type and filer PIC role.
 *
 * Rules source: docs/APPROVAL_RULES.md
 */
class LeaveApprovalPolicy
{
    /**
     * @return list<array{
     *     display_order: int,
     *     allowed_levels: ?array<int, int>,
     *     required_approver_type: ?RequiredApproverType
     * }>
     */
    public function requirementsFor(int $empId, string $leaveTypeCode): array
    {
        return match (strtoupper($leaveTypeCode)) {
            'SL', 'PTB', 'EL', 'LL', 'ML', 'PL' => [
                $this->specialRequirement(1, RequiredApproverType::Admin),
            ],
            default => $this->vlRequirementsForFiler($empId),
        };
    }

    /**
     * VL manager matrix (also used to CC PICs on admin-approved leave / conversion mail).
     *
     * @return list<array{
     *     display_order: int,
     *     allowed_levels: ?array<int, int>,
     *     required_approver_type: ?RequiredApproverType
     * }>
     */
    public function vlRequirementsForFiler(int $empId): array
    {
        $picRole = Formspic::query()
            ->where('fldEmployeeNum', $empId)
            ->value('fldRole');

        // VL: one approval is enough. Designated levels also receive submit email.
        return match ((int) $picRole) {
            1 => [
                $this->managerRequirement(1, [2, 3, 4]),
            ],
            2 => [
                $this->managerRequirement(1, [3, 4]),
            ],
            3 => [
                $this->managerRequirement(1, [4]),
            ],
            4 => [
                $this->specialRequirement(1, RequiredApproverType::President),
            ],
            default => [
                $this->managerRequirement(1, [1, 2, 3, 4]),
            ],
        };
    }

    /**
     * @param  list<int>  $levels
     * @return array{display_order: int, allowed_levels: array<int, int>, required_approver_type: null}
     */
    private function managerRequirement(int $displayOrder, array $levels): array
    {
        return [
            'display_order' => $displayOrder,
            'allowed_levels' => $levels,
            'required_approver_type' => null,
        ];
    }

    /**
     * @return array{display_order: int, allowed_levels: null, required_approver_type: RequiredApproverType}
     */
    private function specialRequirement(int $displayOrder, RequiredApproverType $type): array
    {
        return [
            'display_order' => $displayOrder,
            'allowed_levels' => null,
            'required_approver_type' => $type,
        ];
    }
}
