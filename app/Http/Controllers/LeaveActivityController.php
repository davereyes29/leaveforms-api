<?php

namespace App\Http\Controllers;

use App\Enums\Leave\ActivityType;
use App\Http\Requests\Leave\IndexLeaveActivityRequest;
use App\Http\Resources\LeaveActivityResource;
use App\Services\Leave\LeaveActivityListService;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class LeaveActivityController extends Controller
{
    public function __construct(
        private readonly LeaveActivityListService $activityListService,
    ) {}

    /**
     * GET /api/activities — Activity Log for leaves where the viewer is on leave.
     *
     * Optional query: ?code=VL&activity_type=submitted&sort=desc&page=1&per_page=15
     */
    public function index(IndexLeaveActivityRequest $request): AnonymousResourceCollection
    {
        $activities = $this->activityListService->paginateForEmployee(
            $request->employee()->id,
            $request->validated('code'),
            $request->enum('activity_type', ActivityType::class),
            $request->integer('per_page', 15),
            $request->validated('sort') ?? 'desc',
        );

        return LeaveActivityResource::collection($activities);
    }
}
