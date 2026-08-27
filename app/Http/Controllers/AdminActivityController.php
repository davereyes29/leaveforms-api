<?php

namespace App\Http\Controllers;

use App\Http\Requests\Leave\IndexAdminActivitiesRequest;
use App\Http\Resources\AdminLeaveActivityResource;
use App\Services\Leave\AdminActivityListService;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class AdminActivityController extends Controller
{
    public function __construct(
        private readonly AdminActivityListService $activityListService,
    ) {}

    /**
     * GET /api/admin/activities — org-wide Administrative Log (admin only).
     *
     * Optional query: ?q=sam&group_id=3&code=VL&month=2026-06&sort=desc&page=1&per_page=15
     */
    public function index(IndexAdminActivitiesRequest $request): AnonymousResourceCollection
    {
        $groupId = $request->validated('group_id');
        $q = $request->validated('q');

        $activities = $this->activityListService->paginateForAdmin(
            $request->employee(),
            $request->validated('code'),
            $request->integer('per_page', 15),
            $request->validated('sort') ?? 'desc',
            $groupId !== null ? (int) $groupId : null,
            is_string($q) && $q !== '' ? $q : null,
            $request->validated('month'),
        );

        return AdminLeaveActivityResource::collection($activities);
    }
}
