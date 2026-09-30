<?php

namespace App\Http\Controllers;

use App\Http\Resources\GroupMemberResource;
use App\Services\Leave\LeaveOnBehalfAuthorizationService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class P4MemberController extends Controller
{
    public function __construct(
        private readonly LeaveOnBehalfAuthorizationService $onBehalfAuthorization,
    ) {}

    /**
     * GET /api/p4-members — P4 employees the president may file leave for.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $members = $this->onBehalfAuthorization->eligibleP4Members(
            $request->employee(),
        );

        return GroupMemberResource::collection($members);
    }
}
