<?php

namespace App\Http\Controllers;

use App\Enums\Leave\DayType;
use App\Enums\Leave\LeaveStatus;
use App\Http\Requests\Leave\CancelLeaveRequest;
use App\Http\Requests\Leave\IndexEmployeeLwopRequest;
use App\Http\Requests\Leave\IndexLeaveRequest;
use App\Http\Requests\Leave\IndexVacationLeaveQuarterUsageRequest;
use App\Http\Requests\Leave\StoreLeaveAttachmentsRequest;
use App\Http\Requests\Leave\StoreLeaveRequest;
use App\Http\Requests\Leave\UpdateLeaveRequest;
use App\Http\Resources\EmployeeLwopResource;
use App\Http\Resources\LeaveResource;
use App\Http\Resources\LeaveSummaryResource;
use App\Http\Resources\VacationLeaveQuarterUsageResource;
use App\Models\Leave\LeaveInfo;
use App\Models\Leave\LeaveInfoAttachment;
use App\Services\Leave\EmployeesWithLwopReportService;
use App\Services\Leave\LeaveAttachmentService;
use App\Services\Leave\LeaveCancellationService;
use App\Services\Leave\LeaveListService;
use App\Services\Leave\LeaveModificationService;
use App\Services\Leave\LeaveNotificationService;
use App\Services\Leave\LeaveOnBehalfAuthorizationService;
use App\Services\Leave\LeaveSubmissionService;
use App\Services\Leave\VacationLeaveQuarterUsageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Symfony\Component\HttpFoundation\StreamedResponse;

class LeaveController extends Controller
{
    public function __construct(
        private readonly LeaveSubmissionService $submissionService,
        private readonly LeaveListService $listService,
        private readonly LeaveCancellationService $cancellationService,
        private readonly LeaveModificationService $modificationService,
        private readonly LeaveAttachmentService $attachmentService,
        private readonly LeaveNotificationService $notificationService,
        private readonly LeaveOnBehalfAuthorizationService $onBehalfAuthorization,
        private readonly VacationLeaveQuarterUsageService $quarterUsageService,
        private readonly EmployeesWithLwopReportService $lwopReportService,
    ) {}

    /**
     * GET /api/leaves — list the current employee's leave requests (slim rows).
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

        return LeaveSummaryResource::collection($leaves);
    }

    /**
     * GET /api/leaves/quarter-usage — confirmed VL days in the overlapping quarter(s)
     * plus this request. Informational only (never blocks filing).
     */
    public function quarterUsage(IndexVacationLeaveQuarterUsageRequest $request): VacationLeaveQuarterUsageResource
    {
        $empId = $request->validated('emp_id');

        $target = $this->onBehalfAuthorization->resolveEmployeeOnLeave(
            $request->employee(),
            $empId !== null ? (int) $empId : null,
        );

        $usage = $this->quarterUsageService->preview(
            (int) $target->id,
            (string) $request->validated('start_date'),
            (string) $request->validated('end_date'),
            $request->enum('day_type', DayType::class),
            (string) $request->validated('code'),
        );

        return VacationLeaveQuarterUsageResource::make($usage);
    }

    /**
     * GET /api/leaves/lwop — accepted VL/SL unpaid days for the current
     * Manila calendar year (same rules as the admin LWOP report).
     *
     * Optional query: ?emp_id=212&year=2026
     */
    public function lwop(IndexEmployeeLwopRequest $request): EmployeeLwopResource
    {
        $empId = $request->validated('emp_id');

        $target = $this->onBehalfAuthorization->resolveEmployeeOnLeave(
            $request->employee(),
            $empId !== null ? (int) $empId : null,
        );

        $summary = $this->lwopReportService->forEmployee(
            $target,
            (int) $request->validated('year'),
        );

        return EmployeeLwopResource::make($summary);
    }

    /**
     * GET /api/leaves/{leave} — full leave detail (approvals + conversion).
     */
    public function show(Request $request, LeaveInfo $leave): LeaveResource
    {
        $leave = $this->listService->detailForViewer($leave, $request->employee());

        return LeaveResource::make($leave);
    }

    /**
     * POST /api/leaves — file VL/ML (JSON) or SL/PTB/EL/LL/PL/SPL (multipart + required attachments).
     */
    public function store(StoreLeaveRequest $request): JsonResponse
    {
        $leave = $this->submissionService->submit(
            $request->employee(),
            $request->validated(),
        );

        return LeaveResource::make($leave)
            ->response()
            ->setStatusCode(201);
    }

    /**
     * PUT|POST /api/leaves/{leave} — edit a pending leave request (no approvals yet).
     *
     * JSON (or form fields) for dates/reason. SL, PTB, EL, LL, PL, and SPL files are added or removed
     * through /leaves/{id}/attachments.
     */
    public function update(UpdateLeaveRequest $request, LeaveInfo $leave): LeaveResource
    {
        $leave = $this->modificationService->update(
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
     * POST /api/leaves/{leave}/attachments — add files to a pending SL, PTB, EL, LL, or PL.
     */
    public function storeAttachments(StoreLeaveAttachmentsRequest $request, LeaveInfo $leave): LeaveResource
    {
        $leave = $this->attachmentService->addToLeave(
            $leave,
            $request->employee(),
            $request->uploadedAttachments(),
        );

        $this->notificationService->notifyApproversOnEdit($leave);

        return LeaveResource::make($leave);
    }

    /**
     * DELETE /api/leaves/{leave}/attachments/{attachment} — remove one file (at least one must remain).
     */
    public function destroyAttachment(
        Request $request,
        LeaveInfo $leave,
        LeaveInfoAttachment $attachment,
    ): LeaveResource {
        $leave = $this->attachmentService->removeFromLeave(
            $leave,
            $attachment,
            $request->employee(),
        );

        $this->notificationService->notifyApproversOnEdit($leave);

        return LeaveResource::make($leave);
    }

    /**
     * GET /api/leaves/{leave}/attachments/{attachment} — download one attached file.
     */
    public function downloadAttachment(
        Request $request,
        LeaveInfo $leave,
        LeaveInfoAttachment $attachment,
    ): StreamedResponse {
        return $this->attachmentService->downloadLeaveAttachment(
            $leave,
            $attachment,
            $request->employee(),
        );
    }
}
