<?php

namespace App\Http\Controllers;

use App\Http\Requests\Leave\RejectLeaveConversionRequest;
use App\Http\Requests\Leave\StoreConversionAttachmentsRequest;
use App\Http\Requests\Leave\StoreLeaveConversionRequest;
use App\Http\Resources\LeaveConversionResource;
use App\Models\Leave\LeaveConversion;
use App\Models\Leave\LeaveConversionAttachment;
use App\Models\Leave\LeaveInfo;
use App\Services\Leave\LeaveAttachmentService;
use App\Services\Leave\LeaveConversionService;
use App\Services\Leave\PendingConversionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Symfony\Component\HttpFoundation\StreamedResponse;

class LeaveConversionController extends Controller
{
    public function __construct(
        private readonly LeaveConversionService $conversionService,
        private readonly PendingConversionService $pendingConversionService,
        private readonly LeaveAttachmentService $attachmentService,
    ) {}

    /**
     * POST /api/leaves/{leave}/conversion — employee requests VL → SL or VL → EL.
     */
    public function store(StoreLeaveConversionRequest $request, LeaveInfo $leave): JsonResponse
    {
        $conversion = $this->conversionService->request(
            $leave,
            $request->employee(),
            $request->uploadedAttachments(),
            $request->validated('reason'),
            $request->validated('to_code'),
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
     * POST /api/conversions/{conversion}/attachments — add files while pending review.
     */
    public function storeAttachments(
        StoreConversionAttachmentsRequest $request,
        LeaveConversion $conversion,
    ): LeaveConversionResource {
        $conversion = $this->attachmentService->addToConversion(
            $conversion,
            $request->employee(),
            $request->uploadedAttachments(),
        );

        return LeaveConversionResource::make($conversion);
    }

    /**
     * DELETE /api/conversions/{conversion}/attachments/{attachment}
     */
    public function destroyAttachment(
        Request $request,
        LeaveConversion $conversion,
        LeaveConversionAttachment $attachment,
    ): LeaveConversionResource {
        $conversion = $this->attachmentService->removeFromConversion(
            $conversion,
            $attachment,
            $request->employee(),
        );

        return LeaveConversionResource::make($conversion);
    }

    /**
     * GET /api/conversions/{conversion}/attachments/{attachment}
     */
    public function downloadAttachment(
        Request $request,
        LeaveConversion $conversion,
        LeaveConversionAttachment $attachment,
    ): StreamedResponse {
        return $this->attachmentService->downloadConversionAttachment(
            $conversion,
            $attachment,
            $request->employee(),
        );
    }
}
