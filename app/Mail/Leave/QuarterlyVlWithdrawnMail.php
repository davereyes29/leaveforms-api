<?php

namespace App\Mail\Leave;

use App\Models\Leave\LeaveInfo;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class QuarterlyVlWithdrawnMail extends Mailable
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
        public string $reason,
    ) {
        $this->embedLeaveMailIcons('cancelled.png');
    }

    public function envelope(): Envelope
    {
        $quarter = $this->usage['quarter'] ?? 'this quarter';

        return new Envelope(
            subject: 'Quarterly VL notice withdrawn — '.$this->employeeName.' ('.$quarter.')',
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.leave.quarterly-vl-withdrawn',
            with: [
                'employeeName' => $this->employeeName,
                'adminName' => $this->adminName,
                'usage' => $this->usage,
                'reason' => $this->reason,
                'reasonLabel' => $this->reasonLabel(),
                'frontendUrl' => config('leave.frontend_url'),
            ],
        );
    }

    private function reasonLabel(): string
    {
        return match ($this->reason) {
            'rejected' => 'rejected',
            'edited' => 'edited so it no longer exceeds the quarterly cap',
            default => 'cancelled',
        };
    }
}
