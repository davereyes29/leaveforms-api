<?php

namespace App\Http\Controllers;

use App\Http\Requests\Leave\StoreLeaveReminderRequest;
use App\Models\Leave\LeaveInfo;
use App\Services\Leave\AdminReminderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminReminderController extends Controller
{
    public function __construct(
        private readonly AdminReminderService $reminderService,
    ) {}

    /**
     * GET /api/admin/reminders — banner stats (admin only).
     */
    public function show(Request $request): JsonResponse
    {
        return response()->json(
            $this->reminderService->statsForAdmin($request->employee()),
        );
    }

    /**
     * POST /api/admin/reminders — org-wide generic reminder (admin only).
     */
    public function store(Request $request): JsonResponse
    {
        return response()->json(
            $this->reminderService->sendBulk($request->employee()),
        );
    }

    /**
     * POST /api/leaves/{leave}/reminders — one leave, one waiting approver (admin only).
     */
    public function storeForLeave(StoreLeaveReminderRequest $request, LeaveInfo $leave): JsonResponse
    {
        return response()->json(
            $this->reminderService->sendSingle(
                $leave,
                $request->employee(),
                (int) $request->validated('approver_emp_id'),
            ),
        );
    }
}
