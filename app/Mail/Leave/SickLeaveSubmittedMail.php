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

class SickLeaveSubmittedMail extends Mailable
{
    use AttachesStoredDiskFiles, EmbedsLeaveMailIcons, Queueable, SerializesModels;

    public function __construct(
        public LeaveInfo $leave,
        public string $employeeName,
        public string $adminName,
    ) {
        $this->embedLeaveMailIcons('pending-conversion.png');
    }

    public function envelope(): Envelope
    {
        $this->leave->loadMissing('leaveType');
        $leaveTypeName = $this->leave->leaveType?->mailLabel() ?? 'Leave';

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
                'attachmentNote' => $this->attachmentNote(),
            ],
        );
    }

    /**
     * @return array<int, Attachment>
     */
    public function attachments(): array
    {
        $this->leave->loadMissing('attachments');

        return $this->attachmentsFromStoredFiles($this->leave->attachments);
    }

    private function attachmentNote(): ?string
    {
        $this->leave->loadMissing(['leaveType', 'attachments']);

        $label = LeaveTypeRules::attachmentLabel((string) $this->leave->leaveType?->code);
        $count = $this->leave->attachments->count();

        if ($label === null) {
            return null;
        }

        if ($count <= 1) {
            return 'The '.$label['singular'].' is attached for your review.';
        }

        return $count.' '.$label['plural'].' are attached for your review.';
    }
}
