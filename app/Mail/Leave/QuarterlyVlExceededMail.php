<?php

namespace App\Mail\Leave;

use App\Models\Leave\LeaveInfo;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class QuarterlyVlExceededMail extends Mailable
{
    use EmbedsLeaveMailIcons, Queueable, SerializesModels;

    /**
     * @param  array<string, mixed>  $usage
     */
    public function __construct(
        public LeaveInfo $leave,
        public string $employeeName,
        public string $adminName,
        public array $usage,
    ) {
        $this->embedLeaveMailIcons('approved.png');
    }

    public function envelope(): Envelope
    {
        $quarter = $this->usage['quarter'] ?? 'this quarter';

        return new Envelope(
            subject: $this->employeeName.' exceeded 9 VL days ('.$quarter.')',
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.leave.quarterly-vl-exceeded',
            with: [
                'employeeName' => $this->employeeName,
                'adminName' => $this->adminName,
                'usage' => $this->usage,
                'frontendUrl' => config('leave.frontend_url'),
            ],
        );
    }
}
