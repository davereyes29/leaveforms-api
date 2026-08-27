<?php

namespace App\Http\Controllers;

use App\Http\Requests\Leave\RejectLeaveConversionRequest;
use App\Http\Requests\Leave\StoreLeaveConversionRequest;
use App\Http\Resources\LeaveConversionResource;
use App\Models\Leave\LeaveConversion;
use App\Models\Leave\LeaveInfo;
use App\Services\Leave\LeaveConversionService;
use App\Services\Leave\PendingConversionService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class LeaveConversionController extends Controller
{
    public function __construct(
        private readonly LeaveConversionService $conversionService,
        private readonly PendingConversionService $pendingConversionService,
    ) {}

    /**
     * POST /api/leaves/{leave}/conversion — employee requests VL → SL with med cert and reason.
     */
    public function store(StoreLeaveConversionRequest $request, LeaveInfo $leave): JsonResponse
    {
        $conversion = $this->conversionService->request(
            $leave,
            $request->employee(),
            $request->file('med_cert'),
            $request->validated('reason'),
        );

        return LeaveConversionResource::make($conversion)
            ->response()
            ->setStatusCode(201);
    }

    /**
     * GET /api/conversions/pending — admin inbox.
     */
    public function pending(Request $request): AnonymousResourceCollection
    {
        $conversions = $this->pendingConversionService->forAdmin($request->employee());

        return LeaveConversionResource::collection($conversions);
    }

    /**
     * POST /api/conversions/{conversion}/approve
     */
    public function approve(Request $request, LeaveConversion $conversion): LeaveConversionResource
    {
        $conversion = $this->conversionService->approve($conversion, $request->employee());

        return LeaveConversionResource::make($conversion);
    }

    /**
     * POST /api/conversions/{conversion}/reject
     */
    public function reject(RejectLeaveConversionRequest $request, LeaveConversion $conversion): LeaveConversionResource
    {
        $conversion = $this->conversionService->reject(
            $conversion,
            $request->employee(),
            $request->validated('remarks'),
        );

        return LeaveConversionResource::make($conversion);
    }

    /**
     * GET /api/conversions/{conversion}/med-cert
     */
    public function medCert(Request $request, LeaveConversion $conversion): StreamedResponse
    {
        if (! $this->conversionService->canAccessMedCert($conversion, $request->employee())) {
            throw new AuthorizationException('You are not allowed to access this medical certificate.');
        }

        $path = $conversion->med_cert_attachment;
        $disk = config('leave.attachments.disk', 'local');

        if ($path === null || ! Storage::disk($disk)->exists($path)) {
            abort(404, 'Medical certificate not found.');
        }

        return Storage::disk($disk)->download($path, basename($path));
    }
}
