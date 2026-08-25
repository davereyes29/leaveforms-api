<?php

namespace App\Mail\Leave;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class LeaveReminderDigestMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $approverName,
        public int $pendingCount,
    ) {}

    public function envelope(): Envelope
    {
        $noun = $this->pendingCount === 1 ? 'leave request' : 'leave requests';

        return new Envelope(
            subject: "Reminder: you have {$this->pendingCount} pending {$noun}",
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.leave.reminder-digest',
            with: [
                'approverName' => $this->approverName,
                'pendingCount' => $this->pendingCount,
                'frontendUrl' => config('leave.frontend_url'),
            ],
        );
    }
}
