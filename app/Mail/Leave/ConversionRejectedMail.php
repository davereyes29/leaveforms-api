<?php

namespace App\Mail\Leave;

use App\Models\Leave\LeaveInfo;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use App\Models\Leave\LeaveConversion;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class ConversionRejectedMail extends Mailable
{
    use EmbedsLeaveMailIcons, Queueable, SerializesModels;

    public function __construct(
        public LeaveInfo $leave,
        public LeaveConversion $conversion,
        public string $employeeName,
        public string $recipientName,
        public string $adminName,
        public string $remarks,
    ) {
        $this->embedLeaveMailIcons('rejected.png');
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Your leave conversion request has been rejected',
        );
    }

    public function content(): Content
    {
        $this->leave->loadMissing('leaveType');

        return new Content(
            markdown: 'mail.leave.conversion-rejected',
            with: [
                'leave' => $this->leave,
                'conversion' => $this->conversion,
                'employeeName' => $this->employeeName,
                'recipientName' => $this->recipientName,
                'adminName' => $this->adminName,
                'remarks' => $this->remarks,
                'frontendUrl' => config('leave.frontend_url'),
            ],
        );
    }
}
