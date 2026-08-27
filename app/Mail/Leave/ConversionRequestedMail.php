<?php

namespace App\Mail\Leave;

use App\Models\Leave\LeaveConversion;
use App\Models\Leave\LeaveInfo;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;

class ConversionRequestedMail extends Mailable
{
    use EmbedsLeaveMailIcons, Queueable, SerializesModels;

    public function __construct(
        public LeaveInfo $leave,
        public LeaveConversion $conversion,
        public string $employeeName,
        public string $adminName,
    ) {
        $this->embedLeaveMailIcons('pending-conversion.png');
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Leave conversion request awaiting your review',
        );
    }

    public function content(): Content
    {
        $this->leave->loadMissing('leaveType');
        $this->conversion->loadMissing(['fromLeaveType', 'toLeaveType']);

        return new Content(
            markdown: 'mail.leave.conversion-requested',
            with: [
                'leave' => $this->leave,
                'conversion' => $this->conversion,
                'employeeName' => $this->employeeName,
                'adminName' => $this->adminName,
                'frontendUrl' => config('leave.frontend_url'),
            ],
        );
    }

    /**
     * @return array<int, Attachment>
     */
    public function attachments(): array
    {
        $path = $this->conversion->med_cert_attachment;

        if ($path === null || $path === '') {
            return [];
        }

        $disk = (string) config('leave.attachments.disk', 'local');

        if (! Storage::disk($disk)->exists($path)) {
            return [];
        }

        $extension = pathinfo($path, PATHINFO_EXTENSION);
        $as = 'medical-certificate'.($extension !== '' ? '.'.$extension : '');

        return [
            Attachment::fromStorageDisk($disk, $path)->as($as),
        ];
    }
}
