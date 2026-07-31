<?php

namespace App\Http\Controllers;

use App\Models\Kdtph\Employee;
use App\Services\Leave\LeaveApproverResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Returns the employee resolved from the portal cookie.
 * Use this to verify auth works before building leave endpoints.
 */
class MeController extends Controller
{
    public function __construct(
        private readonly LeaveApproverResolver $approverResolver,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        /** @var Employee $employee */
        $employee = $request->attributes->get('employee');
        $role = $this->approverResolver->contextFor((int) $employee->id);

        return response()->json([
            'id' => $employee->id,
            'firstname' => $employee->firstname,
            'surname' => $employee->surname,
            'full_name' => $employee->fullName(),
            'email' => $employee->email,
            'username' => $employee->username,
            'group_id' => $employee->group_id,
            'group' => $employee->group ? [
                'id' => $employee->group->id,
                'abbreviation' => $employee->group->abbreviation,
                'name' => $employee->group->name,
            ] : null,
            'date_hired' => $employee->date_hired?->toDateString(),
            'role' => [
                'approval_level' => $role->approvalLevel,
                'is_president' => $role->isPresident,
                'is_admin' => $role->isAdmin,
                'pic_groups' => $role->groupCodes,
            ],
        ]);
    }
}
