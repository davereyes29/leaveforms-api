<?php

namespace App\Http\Controllers;

use App\Mail\Leave\ConversionApprovedMail;
use App\Mail\Leave\ConversionRejectedMail;
use App\Mail\Leave\ConversionRequestedMail;
use App\Mail\Leave\LeaveCancelledMail;
use App\Mail\Leave\LeaveConfirmedMail;
use App\Mail\Leave\LeaveRejectedMail;
use App\Mail\Leave\LeaveReminderDigestMail;
use App\Mail\Leave\LeaveReminderMail;
use App\Mail\Leave\LeaveSubmittedMail;
use App\Models\Leave\LeaveConversion;
use App\Models\Leave\LeaveInfo;
use App\Models\Leave\LeaveType;
use Illuminate\Contracts\View\View;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Local-only helper — render leave mail templates in the browser without sending.
 */
class DevMailPreviewController extends Controller
{
    /**
     * @var array<string, string>
     */
    private const TEMPLATES = [
        'submitted' => 'Leave submitted (to approver)',
        'confirmed' => 'Leave approved',
        'rejected' => 'Leave rejected',
        'cancelled' => 'Leave cancelled',
        'reminder' => 'Single reminder (to approver)',
        'reminder-digest' => 'Bulk reminder (pending count)',
        'conversion-requested' => 'VL → SL conversion requested',
        'conversion-approved' => 'VL → SL conversion approved',
        'conversion-rejected' => 'VL → SL conversion rejected',
    ];

    /**
     * GET /dev/mail — list of preview links.
     */
    public function index(): View
    {
        $this->ensureLocal();

        return view('dev.mail-index', [
            'templates' => self::TEMPLATES,
        ]);
    }

    /**
     * GET /dev/mail/{template} — rendered mailable HTML.
     */
    public function show(string $template): Mailable
    {
        $this->ensureLocal();

        $employeeName = 'Jane Employee';
        $approverName = 'Alex';
        $adminName = "Ma'am Arlene";

        return match ($template) {
            'submitted' => new LeaveSubmittedMail(
                $this->sampleLeave(),
                $employeeName,
                $approverName,
            ),
            'confirmed' => new LeaveConfirmedMail(
                $this->sampleLeave(),
                $employeeName,
                $employeeName,
                $approverName,
            ),
            'rejected' => new LeaveRejectedMail(
                $this->sampleLeave(),
                $employeeName,
                $employeeName,
                $approverName,
                'Coverage is insufficient (sample).',
            ),
            'cancelled' => new LeaveCancelledMail(
                $this->sampleLeave(),
                $employeeName,
                $approverName,
                'Plans changed (sample).',
            ),
            'reminder' => new LeaveReminderMail(
                $this->sampleLeave(),
                $employeeName,
                $approverName,
            ),
            'reminder-digest' => new LeaveReminderDigestMail($approverName, 3),
            'conversion-requested' => new ConversionRequestedMail(
                $this->sampleLeave(),
                $this->sampleConversion(),
                $employeeName,
                $adminName,
            ),
            'conversion-approved' => new ConversionApprovedMail(
                $this->sampleLeave(),
                $this->sampleConversion(),
                $employeeName,
                $employeeName,
                $adminName,
            ),
            'conversion-rejected' => new ConversionRejectedMail(
                $this->sampleLeave(),
                $this->sampleConversion(),
                $employeeName,
                $employeeName,
                $adminName,
                'Medical certificate is incomplete (sample).',
            ),
            default => abort(404),
        };
    }

    private function ensureLocal(): void
    {
        if (! app()->isLocal()) {
            abort(404);
        }
    }

    private function sampleLeave(): LeaveInfo
    {
        try {
            if (Schema::hasTable('leave_info')) {
                $leave = LeaveInfo::query()->with('leaveType')->latest('id')->first();

                if ($leave !== null) {
                    if ($leave->leaveType === null) {
                        $leave->setRelation('leaveType', $this->dummyLeaveType('VL', 'Vacation Leave'));
                    }

                    return $leave;
                }
            }
        } catch (Throwable) {
            // Fall through to sample data when the table is missing or empty.
        }

        return $this->dummyLeave();
    }

    private function sampleConversion(): LeaveConversion
    {
        try {
            if (Schema::hasTable('leave_conversion')) {
                $conversion = LeaveConversion::query()
                    ->with(['fromLeaveType', 'toLeaveType'])
                    ->latest('id')
                    ->first();

                if ($conversion !== null) {
                    if ($conversion->fromLeaveType === null) {
                        $conversion->setRelation('fromLeaveType', $this->dummyLeaveType('VL', 'Vacation Leave'));
                    }

                    if ($conversion->toLeaveType === null) {
                        $conversion->setRelation('toLeaveType', $this->dummyLeaveType('SL', 'Sick Leave'));
                    }

                    return $conversion;
                }
            }
        } catch (Throwable) {
            // Fall through to sample data when the table is missing or empty.
        }

        $conversion = new LeaveConversion;
        $conversion->setRelation('fromLeaveType', $this->leaveTypeByCode('VL', 'Vacation Leave'));
        $conversion->setRelation('toLeaveType', $this->leaveTypeByCode('SL', 'Sick Leave'));

        return $conversion;
    }

    private function dummyLeave(): LeaveInfo
    {
        $leave = new LeaveInfo([
            'duration' => '1.00',
            'start_date' => '2026-08-26',
            'end_date' => '2026-08-26',
            'leave_reason' => 'Sample vacation for mail preview.',
        ]);
        $leave->setRelation('leaveType', $this->leaveTypeByCode('VL', 'Vacation Leave'));

        return $leave;
    }

    private function leaveTypeByCode(string $code, string $type): LeaveType
    {
        try {
            if (Schema::hasTable('leave_type')) {
                $leaveType = LeaveType::query()->where('code', $code)->first();

                if ($leaveType !== null) {
                    return $leaveType;
                }
            }
        } catch (Throwable) {
            // Fall through to sample data.
        }

        return $this->dummyLeaveType($code, $type);
    }

    private function dummyLeaveType(string $code, string $type): LeaveType
    {
        return new LeaveType([
            'code' => $code,
            'type' => $type,
        ]);
    }
}
