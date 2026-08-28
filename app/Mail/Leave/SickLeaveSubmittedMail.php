<?php

namespace App\Mail\Leave;

use App\Models\Leave\LeaveInfo;
use App\Services\Leave\LeaveTypeRules;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class SickLeaveSubmittedMail extends Mailable
{
    use AttachesStoredDiskFiles, Queueable, SerializesModels;

    public function __construct(
        public LeaveInfo $leave,
        public string $employeeName,
        public string $adminName,
    ) {}

    public function envelope(): Envelope
    {
        $this->leave->loadMissing('leaveType');
        $leaveTypeName = $this->leave->leaveType?->type ?? 'Leave';

        return new Envelope(
            subject: $leaveTypeName.' request awaiting your approval',
        );
    }

    public function content(): Content
    {
        $this->leave->loadMissing(['leaveType', 'attachments']);

        return new Content(
            markdown: 'mail.leave.sick-leave-submitted',
            with: [
                'leave' => $this->leave,
                'employeeName' => $this->employeeName,
                'adminName' => $this->adminName,
                'frontendUrl' => config('leave.frontend_url'),
                'attachmentSentence' => $this->attachmentSentence(),
            ],
        );
    }

    /**
     * @return array<int, Attachment>
     */
    public function attachments(): array
    {
        $this->leave->loadMissing('attachments');

        return $this->attachmentsFromStoredFiles($this->leave->attachments);
    }

    private function attachmentSentence(): ?string
    {
        $this->leave->loadMissing(['leaveType', 'attachments']);
        $code = (string) $this->leave->leaveType?->code;

        if (! LeaveTypeRules::requiresAttachment($code)) {
            return null;
        }

        $count = $this->leave->attachments->count();

        if ($count < 1) {
            return null;
        }

        $isMedCert = LeaveTypeRules::requiresMedCert($code);

        if ($count === 1) {
            return $isMedCert
                ? 'A medical certificate is attached for your review.'
                : 'A supporting document is attached for your review.';
        }

        return $isMedCert
            ? $count.' medical certificates are attached for your review.'
            : $count.' supporting documents are attached for your review.';
    }
}
