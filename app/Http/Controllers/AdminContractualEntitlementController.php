<?php

namespace App\Http\Controllers;

use App\Http\Requests\Leave\IndexAdminContractualEntitlementsRequest;
use App\Http\Requests\Leave\StoreAdminContractualEntitlementRequest;
use App\Http\Resources\AdminContractualEntitlementResource;
use App\Services\Leave\AdminContractualEntitlementService;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class AdminContractualEntitlementController extends Controller
{
    public function __construct(
        private readonly AdminContractualEntitlementService $contractualEntitlementService,
    ) {}

    /**
     * GET /api/admin/contractual-entitlements — active contractuals for VL/SL grant (admin only).
     *
     * Optional query: ?q=alex&group_id=16&emp_ids=830,831&sort=asc&page=1&per_page=15
     */
    public function index(IndexAdminContractualEntitlementsRequest $request): AnonymousResourceCollection
    {
        $groupId = $request->validated('group_id');
        $q = $request->validated('q');
        $empIds = $request->validated('emp_ids');

        $result = $this->contractualEntitlementService->paginateForAdmin(
            $request->employee(),
            $request->integer('per_page', 15),
            $groupId !== null ? (int) $groupId : null,
            is_string($q) && $q !== '' ? $q : null,
            $request->validated('sort') ?? 'asc',
            is_array($empIds) ? array_map('intval', $empIds) : null,
        );

        return AdminContractualEntitlementResource::collection($result['paginator'])
            ->preserveQuery()
            ->additional([
                'meta' => $result['summary'],
            ]);
    }

    /**
     * POST /api/admin/contractual-entitlements — confirm typed VL/SL grants (admin only).
     *
     * Body: { employees: [{ emp_id, vl_to_add?, sl_to_add? }] }
     */
    public function store(StoreAdminContractualEntitlementRequest $request): AnonymousResourceCollection
    {
        $result = $this->contractualEntitlementService->distributeForAdmin(
            $request->employee(),
            $request->validated('employees'),
        );

        return AdminContractualEntitlementResource::collection($result['employees'])
            ->additional([
                'as_of' => $result['as_of'],
                'granted_count' => $result['granted_count'],
            ]);
    }
}
