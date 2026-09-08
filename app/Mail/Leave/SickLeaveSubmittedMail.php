<?php

namespace App\Mail\Leave;

use App\Models\Leave\LeaveInfo;
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
        $this->embedLeaveMailIcons('pending.png');
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
}
