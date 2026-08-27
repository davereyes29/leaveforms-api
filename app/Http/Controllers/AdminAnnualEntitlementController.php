<?php

namespace App\Http\Controllers;

use App\Http\Requests\Leave\IndexAdminAnnualEntitlementsRequest;
use App\Http\Requests\Leave\StoreAdminAnnualEntitlementRequest;
use App\Http\Resources\AdminAnnualEntitlementResource;
use App\Services\Leave\AdminAnnualEntitlementService;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class AdminAnnualEntitlementController extends Controller
{
    public function __construct(
        private readonly AdminAnnualEntitlementService $annualEntitlementService,
    ) {}

    /**
     * GET /api/admin/annual-entitlements — eligible regulars for annual VL/SL grant (admin only).
     *
     * Optional query: ?year=2026&q=alex&group_id=16&emp_ids=8,12&sort=asc&page=1&per_page=15
     */
    public function index(IndexAdminAnnualEntitlementsRequest $request): AnonymousResourceCollection
    {
        $groupId = $request->validated('group_id');
        $q = $request->validated('q');
        $empIds = $request->validated('emp_ids');

        $result = $this->annualEntitlementService->paginateForAdmin(
            $request->employee(),
            (int) $request->validated('year'),
            $request->integer('per_page', 15),
            $groupId !== null ? (int) $groupId : null,
            is_string($q) && $q !== '' ? $q : null,
            $request->validated('sort') ?? 'asc',
            is_array($empIds) ? array_map('intval', $empIds) : null,
        );

        return AdminAnnualEntitlementResource::collection($result['paginator'])
            ->preserveQuery()
            ->additional([
                'meta' => $result['summary'],
            ]);
    }

    /**
     * POST /api/admin/annual-entitlements — confirm distribution for checked employees (admin only).
     *
     * Body: { year, emp_ids }
     */
    public function store(StoreAdminAnnualEntitlementRequest $request): AnonymousResourceCollection
    {
        $result = $this->annualEntitlementService->distributeForAdmin(
            $request->employee(),
            (int) $request->validated('year'),
            $request->validated('emp_ids'),
        );

        return AdminAnnualEntitlementResource::collection($result['employees'])
            ->additional([
                'year' => $result['year'],
                'as_of' => $result['as_of'],
                'granted_count' => $result['granted_count'],
            ]);
    }
}
