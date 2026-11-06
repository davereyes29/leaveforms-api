<?php

namespace App\Mail\Leave;

use App\Models\Leave\LeaveInfo;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class LeaveCancelledByAdminMail extends Mailable
{
    use EmbedsLeaveMailIcons, Queueable, SerializesModels;

    public function __construct(
        public LeaveInfo $leave,
        public string $employeeName,
        public string $recipientName,
        public string $adminName,
        public string $cancellationReason,
        public bool $forFiler = false,
    ) {
        $this->embedLeaveMailIcons('cancelled.png');
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: $this->forFiler
                ? "Leave request for {$this->employeeName} has been cancelled"
                : 'Your leave request has been cancelled',
        );
    }

    public function content(): Content
    {
        $this->leave->loadMissing('leaveType');

        return new Content(
            markdown: 'mail.leave.cancelled-by-admin',
            with: [
                'leave' => $this->leave,
                'employeeName' => $this->employeeName,
                'recipientName' => $this->recipientName,
                'adminName' => $this->adminName,
                'cancellationReason' => $this->cancellationReason,
                'forFiler' => $this->forFiler,
                'frontendUrl' => LeaveMailLink::employeeView((int) $this->leave->id),
            ],
        );
    }
}
