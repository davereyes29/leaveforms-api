<?php

namespace App\Http\Controllers;

use App\Enums\Leave\LeaveStatus;
use App\Http\Requests\Leave\CancelLeaveRequest;
use App\Http\Requests\Leave\IndexLeaveRequest;
use App\Http\Requests\Leave\StoreLeaveRequest;
use App\Http\Resources\LeaveResource;
use App\Models\Leave\LeaveInfo;
use App\Services\Leave\LeaveAttachmentService;
use App\Services\Leave\LeaveCancellationService;
use App\Services\Leave\LeaveListService;
use App\Http\Requests\Leave\UpdateLeaveRequest;
use App\Services\Leave\LeaveModificationService;
use App\Services\Leave\LeaveSubmissionService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class LeaveController extends Controller
{
    public function __construct(
        private readonly LeaveSubmissionService $submissionService,
        private readonly LeaveListService $listService,
        private readonly LeaveCancellationService $cancellationService,
        private readonly LeaveModificationService $modificationService,
        private readonly LeaveAttachmentService $attachmentService,
    ) {}

    /**
     * GET /api/leaves — list the current employee's leave requests.
     *
     * Optional query: ?status=pending_approval&per_page=10
     */
    public function index(IndexLeaveRequest $request): AnonymousResourceCollection
    {
        $leaves = $this->listService->paginateForEmployee(
            $request->employee()->id,
            $request->enum('status', LeaveStatus::class),
            $request->integer('per_page', 15),
        );

        return LeaveResource::collection($leaves);
    }

    /**
     * POST /api/leaves — file a VL request (no attachment).
     */
    public function store(StoreLeaveRequest $request): JsonResponse
    {
        $leave = $this->submissionService->submitVl(
            $request->employee(),
            $request->validated(),
        );

        return LeaveResource::make($leave)
            ->response()
            ->setStatusCode(201);
    }

    /**
     * PUT|POST /api/leaves/{leave} — edit a pending VL request (no approvals yet).
     *
     * Use PUT or POST with JSON. VL does not accept attachments.
     * POST + multipart is still allowed for text fields (PHP PUT multipart limitation).
     */
    public function update(UpdateLeaveRequest $request, LeaveInfo $leave): LeaveResource
    {
        $leave = $this->modificationService->updateVl(
            $leave,
            $request->employee(),
            $request->validated(),
        );

        return LeaveResource::make($leave);
    }

    /**
     * POST /api/leaves/{leave}/cancel — employee cancels an open request.
     */
    public function cancel(CancelLeaveRequest $request, LeaveInfo $leave): LeaveResource
    {
        $leave = $this->cancellationService->cancel(
            $leave,
            $request->employee(),
            $request->validated('cancellation_reason'),
        );

        return LeaveResource::make($leave);
    }

    /**
     * GET /api/leaves/{leave}/attachment — download the attached file.
     */
    public function attachment(Request $request, LeaveInfo $leave): StreamedResponse
    {
        if (! $this->attachmentService->canAccess($leave, $request->employee())) {
            throw new AuthorizationException('You are not allowed to access this attachment.');
        }

        $path = $leave->leave_attachment;

        if ($path === null || ! Storage::disk($this->attachmentService->disk())->exists($path)) {
            abort(404, 'Attachment not found.');
        }

        return Storage::disk($this->attachmentService->disk())->download(
            $path,
            basename($path),
        );
    }
}
