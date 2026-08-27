<?php

namespace App\Mail\Leave;

use App\Models\Leave\LeaveInfo;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class LeaveSubmittedMail extends Mailable
{
    use EmbedsLeaveMailIcons, Queueable, SerializesModels;

    public function __construct(
        public LeaveInfo $leave,
        public string $employeeName,
        public string $approverName,
    ) {
        $this->embedLeaveMailIcons('pending.png');
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Leave request awaiting your approval',
        );
    }

    public function content(): Content
    {
        $this->leave->loadMissing('leaveType');

        return new Content(
            markdown: 'mail.leave.submitted',
            with: [
                'leave' => $this->leave,
                'employeeName' => $this->employeeName,
                'approverName' => $this->approverName,
                'frontendUrl' => config('leave.frontend_url'),
            ],
        );
    }
}
