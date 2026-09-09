<?php

namespace App\Http\Controllers;

use App\Mail\Leave\ConversionApprovedMail;
use App\Mail\Leave\ConversionRejectedMail;
use App\Mail\Leave\ConversionRequestedMail;
use App\Mail\Leave\LeaveCancelledByAdminMail;
use App\Mail\Leave\LeaveCancelledMail;
use App\Mail\Leave\LeaveConfirmedMail;
use App\Mail\Leave\LeaveRejectedMail;
use App\Mail\Leave\LeaveReminderDigestMail;
use App\Mail\Leave\LeaveReminderMail;
use App\Mail\Leave\LeaveSubmittedMail;
use App\Mail\Leave\QuarterlyVlExceededMail;
use App\Mail\Leave\QuarterlyVlImpendingMail;
use App\Mail\Leave\QuarterlyVlWithdrawnMail;
use App\Mail\Leave\SickLeaveSubmittedMail;
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
        'sick-leave-submitted' => 'Sick Leave submitted (to admin)',
        'confirmed' => 'Leave approved',
        'rejected' => 'Leave rejected',
        'cancelled' => 'Leave cancelled',
        'cancelled-by-admin' => 'Leave cancelled by admin',
        'reminder' => 'Single reminder (to approver)',
        'reminder-digest' => 'Bulk reminder (pending count)',
        'conversion-requested' => 'Leave conversion requested',
        'conversion-approved' => 'Leave conversion approved',
        'conversion-rejected' => 'Leave conversion rejected',
        'quarterly-vl-impending' => 'Quarterly VL memo impending (to admin)',
        'quarterly-vl-exceeded' => 'Quarterly VL memo exceeded (to admin)',
        'quarterly-vl-withdrawn' => 'Quarterly VL memo withdrawn (to admin)',
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
            'sick-leave-submitted' => new SickLeaveSubmittedMail(
                $this->sampleSickLeave(),
                $employeeName,
                $adminName,
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
            'cancelled-by-admin' => new LeaveCancelledByAdminMail(
                $this->sampleLeave(),
                $employeeName,
                $employeeName,
                $adminName,
                'Employee will report to the office (sample).',
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
            'quarterly-vl-impending' => new QuarterlyVlImpendingMail(
                $this->sampleLeave(),
                $employeeName,
                $adminName,
                $this->sampleQuarterUsage('9.50'),
            ),
            'quarterly-vl-exceeded' => new QuarterlyVlExceededMail(
                $this->sampleLeave(),
                $employeeName,
                $adminName,
                $this->sampleQuarterUsage('10.00', includeThisInTaken: true),
            ),
            'quarterly-vl-withdrawn' => new QuarterlyVlWithdrawnMail(
                $this->sampleLeave(),
                $employeeName,
                $adminName,
                $this->sampleQuarterUsage('9.50'),
                'cancelled',
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
                $leave = LeaveInfo::query()
                    ->with('leaveType')
                    ->with('attachments')
                    ->latest('id')
                    ->first();

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
                    ->with(['fromLeaveType', 'toLeaveType', 'attachments'])
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

        $conversion = new LeaveConversion([
            'reason' => 'Acute respiratory infection (sample).',
        ]);
        $conversion->setRelation('fromLeaveType', $this->leaveTypeByCode('VL', 'Vacation Leave'));
        $conversion->setRelation('toLeaveType', $this->leaveTypeByCode('SL', 'Sick Leave'));
        $conversion->setRelation('attachments', collect());

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
        $leave->setRelation('attachments', collect());

        return $leave;
    }

    private function sampleSickLeave(): LeaveInfo
    {
        try {
            if (Schema::hasTable('leave_info')) {
                $leave = LeaveInfo::query()
                    ->with(['leaveType', 'attachments'])
                    ->whereHas('leaveType', fn ($query) => $query->where('code', 'SL'))
                    ->latest('id')
                    ->first();

                if ($leave !== null) {
                    return $leave;
                }
            }
        } catch (Throwable) {
            // Fall through to sample data.
        }

        $leave = new LeaveInfo([
            'duration' => '1.00',
            'start_date' => '2026-08-26',
            'end_date' => '2026-08-26',
            'leave_reason' => 'Sample sick leave for mail preview.',
        ]);
        $leave->setRelation('leaveType', $this->leaveTypeByCode('SL', 'Sick Leave'));
        $leave->setRelation('attachments', collect());

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

    /**
     * @return array<string, mixed>
     */
    private function sampleQuarterUsage(string $projected, bool $includeThisInTaken = false): array
    {
        $thisRequest = '1.50';
        $taken = $includeThisInTaken ? $projected : '8.00';

        return [
            'applicable' => true,
            'quarter' => '2026-Q3',
            'period' => [
                'start_date' => '2026-07-01',
                'end_date' => '2026-09-30',
            ],
            'taken' => $taken,
            'this_request' => $thisRequest,
            'projected' => $projected,
            'cap' => '9.00',
            'level' => 'exceeded',
            'quarters' => [],
        ];
    }
}
