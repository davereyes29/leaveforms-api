<?php

namespace App\Mail\Leave;

use App\Models\Leave\LeaveConversion;
use App\Models\Leave\LeaveInfo;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class ConversionRequestedMail extends Mailable
{
    use AttachesStoredDiskFiles, EmbedsLeaveMailIcons, Queueable, SerializesModels;

    public function __construct(
        public LeaveInfo $leave,
        public LeaveConversion $conversion,
        public string $employeeName,
        public string $adminName,
    ) {
        $this->embedLeaveMailIcons('pending-conversion.png');
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Leave conversion request awaiting your review',
        );
    }

    public function content(): Content
    {
        $this->leave->loadMissing('leaveType');
        $this->conversion->loadMissing(['fromLeaveType', 'toLeaveType', 'attachments']);

        $count = $this->conversion->attachments->count();
        $attachmentSentence = match (true) {
            $count === 1 => 'A medical certificate is attached for your review.',
            $count > 1 => $count.' medical certificates are attached for your review.',
            default => null,
        };

        return new Content(
            markdown: 'mail.leave.conversion-requested',
            with: [
                'leave' => $this->leave,
                'conversion' => $this->conversion,
                'employeeName' => $this->employeeName,
                'adminName' => $this->adminName,
                'frontendUrl' => config('leave.frontend_url'),
                'attachmentSentence' => $attachmentSentence,
            ],
        );
    }

    /**
     * @return array<int, Attachment>
     */
    public function attachments(): array
    {
        $this->conversion->loadMissing('attachments');

        return $this->attachmentsFromStoredFiles($this->conversion->attachments);
    }
}
