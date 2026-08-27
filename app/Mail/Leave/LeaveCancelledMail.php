<?php

namespace App\Mail\Leave;

use App\Models\Leave\LeaveInfo;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class LeaveCancelledMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public LeaveInfo $leave,
        public string $employeeName,
        public string $approverName,
        public string $cancellationReason,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Leave request cancelled',
        );
    }

    public function content(): Content
    {
        $this->leave->loadMissing('leaveType');

        return new Content(
            markdown: 'mail.leave.cancelled',
            with: [
                'leave' => $this->leave,
                'employeeName' => $this->employeeName,
                'approverName' => $this->approverName,
                'cancellationReason' => $this->cancellationReason,
                'frontendUrl' => config('leave.frontend_url'),
            ],
        );
    }
}
