<?php

namespace App\Mail\Leave;

use App\Models\Leave\LeaveInfo;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use App\Models\Leave\LeaveConversion;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class ConversionApprovedMail extends Mailable
{
    use EmbedsLeaveMailIcons, Queueable, SerializesModels;

    public function __construct(
        public LeaveInfo $leave,
        public LeaveConversion $conversion,
        public string $employeeName,
        public string $recipientName,
        public string $adminName,
    ) {
        $this->embedLeaveMailIcons('approved.png');
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Your leave conversion request has been approved',
        );
    }

    public function content(): Content
    {
        $this->leave->loadMissing('leaveType');
        $this->conversion->loadMissing(['fromLeaveType', 'toLeaveType']);

        return new Content(
            markdown: 'mail.leave.conversion-approved',
            with: [
                'leave' => $this->leave,
                'conversion' => $this->conversion,
                'employeeName' => $this->employeeName,
                'recipientName' => $this->recipientName,
                'adminName' => $this->adminName,
                'frontendUrl' => config('leave.frontend_url'),
            ],
        );
    }
}
