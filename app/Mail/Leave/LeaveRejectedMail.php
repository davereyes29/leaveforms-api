<?php

namespace App\Mail\Leave;

use App\Models\Leave\LeaveInfo;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class LeaveRejectedMail extends Mailable
{
    use EmbedsLeaveMailIcons, Queueable, SerializesModels;

    public function __construct(
        public LeaveInfo $leave,
        public string $employeeName,
        public string $recipientName,
        public string $approverName,
        public string $remarks,
        public bool $forFiler = false,
    ) {
        $this->embedLeaveMailIcons('rejected.png');
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: $this->forFiler
                ? "Leave request for {$this->employeeName} has been rejected"
                : 'Your leave request has been rejected',
        );
    }

    public function content(): Content
    {
        $this->leave->loadMissing('leaveType');

        return new Content(
            markdown: 'mail.leave.rejected',
            with: [
                'leave' => $this->leave,
                'employeeName' => $this->employeeName,
                'recipientName' => $this->recipientName,
                'approverName' => $this->approverName,
                'remarks' => $this->remarks,
                'forFiler' => $this->forFiler,
                'frontendUrl' => LeaveMailLink::employeeView((int) $this->leave->id),
            ],
        );
    }
}
