<?php

namespace App\Http\Controllers;

use App\Enums\Leave\LeaveStatus;
use App\Http\Requests\Leave\CancelLeaveRequest;
use App\Http\Requests\Leave\IndexLeaveRequest;
use App\Http\Requests\Leave\StoreLeaveRequest;
use App\Http\Resources\LeaveResource;
use App\Models\Leave\LeaveInfo;
use App\Services\Leave\LeaveCancellationService;
use App\Services\Leave\LeaveListService;
use App\Services\Leave\LeaveSubmissionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class LeaveController extends Controller
{
    public function __construct(
        private readonly LeaveSubmissionService $submissionService,
        private readonly LeaveListService $listService,
        private readonly LeaveCancellationService $cancellationService,
    ) {}

    /**
     * GET /api/leaves — list the current employee's leave requests.
     *
     * Optional query: ?status=pending_approval&per_page=10
     */
    public function index(IndexLeaveRequest $request): AnonymousResourceCollection
    {
        $leaves = $this->listService->paginateForEmployee(
            $request->employee()->id,
            $request->enum('status', LeaveStatus::class),
            $request->integer('per_page', 15),
        );

        return LeaveResource::collection($leaves);
    }

    /**
     * POST /api/leaves — file a VL request.
     */
    public function store(StoreLeaveRequest $request): JsonResponse
    {
        $leave = $this->submissionService->submitVl(
            $request->employee(),
            $request->validated(),
        );

        return LeaveResource::make($leave)
            ->response()
            ->setStatusCode(201);
    }

    /**
     * POST /api/leaves/{leave}/cancel — employee cancels an open request.
     */
    public function cancel(CancelLeaveRequest $request, LeaveInfo $leave): LeaveResource
    {
        $leave = $this->cancellationService->cancel(
            $leave,
            $request->employee(),
            $request->validated('cancellation_reason'),
        );

        return LeaveResource::make($leave);
    }
}
