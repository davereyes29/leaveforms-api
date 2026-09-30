<?php

namespace App\Http\Controllers;

use App\Http\Requests\Leave\IndexTeamCalendarRequest;
use App\Http\Resources\TeamCalendarResource;
use App\Services\Leave\TeamCalendarService;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class TeamCalendarController extends Controller
{
    public function __construct(
        private readonly TeamCalendarService $calendarService,
    ) {}

    /**
     * GET /api/team/calendar — approved leaves for the viewer's team (and self).
     * President: P4 employees only (group_id ignored).
     *
     * Query: from + to (YYYY-MM-DD), optional code, optional group_id
     */
    public function index(IndexTeamCalendarRequest $request): AnonymousResourceCollection
    {
        [$from, $to] = $request->resolvedRange();

        $groupId = $request->validated('group_id');

        $leaves = $this->calendarService->forApprover(
            $request->employee(),
            $from->toDateString(),
            $to->toDateString(),
            $request->validated('code'),
            $groupId !== null ? (int) $groupId : null,
        );

        return TeamCalendarResource::collection($leaves);
    }
}
