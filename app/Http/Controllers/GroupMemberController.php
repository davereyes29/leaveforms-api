<?php

namespace App\Http\Controllers;

use App\Http\Resources\GroupMemberResource;
use App\Models\Kdtph\Group;
use App\Services\Leave\LeaveOnBehalfAuthorizationService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class GroupMemberController extends Controller
{
    public function __construct(
        private readonly LeaveOnBehalfAuthorizationService $onBehalfAuthorization,
    ) {}

    /**
     * GET /api/groups/{group}/members — employees this PIC may file leave for.
     */
    public function index(Request $request, Group $group): AnonymousResourceCollection
    {
        $members = $this->onBehalfAuthorization->eligibleMembersInGroup(
            $request->employee(),
            $group,
        );

        return GroupMemberResource::collection($members);
    }
}
