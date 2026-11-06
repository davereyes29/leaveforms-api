<?php

namespace App\Mail\Leave;

use App\Models\Leave\LeaveInfo;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class LeaveConfirmedMail extends Mailable
{
    use EmbedsLeaveMailIcons, Queueable, SerializesModels;

    public function __construct(
        public LeaveInfo $leave,
        public string $employeeName,
        public string $recipientName,
        public string $approverName,
        public bool $forFiler = false,
    ) {
        $this->embedLeaveMailIcons('approved.png');
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: $this->forFiler
                ? "Leave request for {$this->employeeName} has been approved"
                : 'Your leave request has been approved',
        );
    }

    public function content(): Content
    {
        $this->leave->loadMissing('leaveType');

        return new Content(
            markdown: 'mail.leave.confirmed',
            with: [
                'leave' => $this->leave,
                'employeeName' => $this->employeeName,
                'recipientName' => $this->recipientName,
                'approverName' => $this->approverName,
                'forFiler' => $this->forFiler,
                'frontendUrl' => LeaveMailLink::employeeView((int) $this->leave->id),
            ],
        );
    }
}
