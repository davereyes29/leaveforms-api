<?php

namespace App\Http\Controllers;

use App\Http\Requests\Leave\IndexAdminNewlyRegularizedRequest;
use App\Http\Requests\Leave\StoreAdminNewlyRegularizedRequest;
use App\Http\Resources\AdminNewlyRegularizedResource;
use App\Services\Leave\AdminNewlyRegularizedService;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class AdminNewlyRegularizedController extends Controller
{
    public function __construct(
        private readonly AdminNewlyRegularizedService $newlyRegularizedService,
    ) {}

    /**
     * GET /api/admin/newly-regularized — mid-year regularization candidates (admin only).
     *
     * Optional query: ?year=2026&q=alex&group_id=16&emp_ids=8,12&sort=asc&page=1&per_page=15
     */
    public function index(IndexAdminNewlyRegularizedRequest $request): AnonymousResourceCollection
    {
        $groupId = $request->validated('group_id');
        $q = $request->validated('q');
        $empIds = $request->validated('emp_ids');

        $result = $this->newlyRegularizedService->paginateForAdmin(
            $request->employee(),
            (int) $request->validated('year'),
            $request->integer('per_page', 15),
            $groupId !== null ? (int) $groupId : null,
            is_string($q) && $q !== '' ? $q : null,
            $request->validated('sort') ?? 'asc',
            is_array($empIds) ? array_map('intval', $empIds) : null,
        );

        return AdminNewlyRegularizedResource::collection($result['paginator'])
            ->preserveQuery()
            ->additional([
                'meta' => $result['summary'],
            ]);
    }

    /**
     * POST /api/admin/newly-regularized — confirm distribution for checked employees (admin only).
     *
     * Body: { year, emp_ids }
     */
    public function store(StoreAdminNewlyRegularizedRequest $request): AnonymousResourceCollection
    {
        $result = $this->newlyRegularizedService->distributeForAdmin(
            $request->employee(),
            (int) $request->validated('year'),
            $request->validated('emp_ids'),
        );

        return AdminNewlyRegularizedResource::collection($result['employees'])
            ->additional([
                'year' => $result['year'],
                'as_of' => $result['as_of'],
                'granted_count' => $result['granted_count'],
            ]);
    }
}
