<?php

namespace App\Http\Controllers;

use App\Http\Requests\Dev\DevLoginRequest;
use App\Models\Kdtph\Employee;
use App\Models\Kdtph\KdtLogin;
use Illuminate\Http\JsonResponse;

/**
 * Local-only helper — returns the portal userID hash for an employee id.
 *
 * The React app sets document.cookie itself (same-origin / Vite proxy),
 * then loads profile via GET /api/me.
 */
class DevLoginController extends Controller
{
    public function __invoke(DevLoginRequest $request): JsonResponse
    {
        if (! app()->isLocal()) {
            abort(404);
        }

        $empId = (int) $request->validated('emp_id');

        $login = KdtLogin::query()
            ->where('fldEmployeeNum', $empId)
            ->first();

        if ($login === null || $login->fldUserHash === null || $login->fldUserHash === '') {
            return response()->json([
                'message' => 'No portal login hash found for this employee.',
            ], 404);
        }

        $employee = Employee::query()->find($empId);

        if ($employee === null || ! $employee->isActive()) {
            return response()->json([
                'message' => 'Employee not found or inactive.',
            ], 404);
        }

        return response()->json([
            'userID' => $login->fldUserHash,
            'cookie' => config('leave.portal_cookie_name'),
        ]);
    }
}
