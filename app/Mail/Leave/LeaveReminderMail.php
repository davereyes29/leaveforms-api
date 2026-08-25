<?php

namespace App\Mail\Leave;

use App\Models\Leave\LeaveInfo;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class LeaveReminderMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public LeaveInfo $leave,
        public string $employeeName,
        public string $approverName,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Reminder: leave request pending your approval',
        );
    }

    public function content(): Content
    {
        $this->leave->loadMissing('leaveType');

        return new Content(
            markdown: 'mail.leave.reminder',
            with: [
                'leave' => $this->leave,
                'employeeName' => $this->employeeName,
                'approverName' => $this->approverName,
                'frontendUrl' => config('leave.frontend_url'),
            ],
        );
    }
}
