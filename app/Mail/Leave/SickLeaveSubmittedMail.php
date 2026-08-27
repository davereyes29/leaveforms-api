<?php

namespace App\Mail\Leave;

use App\Models\Leave\LeaveInfo;
use App\Services\Leave\LeaveTypeRules;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;

class SickLeaveSubmittedMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public LeaveInfo $leave,
        public string $employeeName,
        public string $adminName,
    ) {}

    public function envelope(): Envelope
    {
        $this->leave->loadMissing('leaveType');
        $leaveTypeName = $this->leave->leaveType?->type ?? 'Leave';

        return new Envelope(
            subject: $leaveTypeName.' request awaiting your approval',
        );
    }

    public function content(): Content
    {
        $this->leave->loadMissing('leaveType');

        return new Content(
            markdown: 'mail.leave.sick-leave-submitted',
            with: [
                'leave' => $this->leave,
                'employeeName' => $this->employeeName,
                'adminName' => $this->adminName,
                'frontendUrl' => config('leave.frontend_url'),
                'attachmentLabel' => $this->attachmentLabel(),
            ],
        );
    }

    /**
     * @return array<int, Attachment>
     */
    public function attachments(): array
    {
        $this->leave->loadMissing('leaveType');

        $path = $this->leave->leave_attachment;

        if ($path === null || $path === '') {
            return [];
        }

        $disk = (string) config('leave.attachments.disk', 'local');

        if (! Storage::disk($disk)->exists($path)) {
            return [];
        }

        $extension = pathinfo($path, PATHINFO_EXTENSION);
        $basename = LeaveTypeRules::requiresMedCert((string) $this->leave->leaveType?->code)
            ? 'medical-certificate'
            : 'supporting-document';
        $as = $basename.($extension !== '' ? '.'.$extension : '');

        return [
            Attachment::fromStorageDisk($disk, $path)->as($as),
        ];
    }

    private function attachmentLabel(): ?string
    {
        $this->leave->loadMissing('leaveType');
        $code = (string) $this->leave->leaveType?->code;

        if (! LeaveTypeRules::requiresAttachment($code)) {
            return null;
        }

        return LeaveTypeRules::requiresMedCert($code)
            ? 'medical certificate'
            : 'supporting document';
    }
}
