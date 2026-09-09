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
        $this->embedLeaveMailIcons('pending.png');
    }

    public function envelope(): Envelope
    {
        $quarter = $this->quarterLabel();

        return new Envelope(
            subject: 'Quarterly VL limit reminder — '.$this->employeeName.' ('.$quarter.')',
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
                'leave' => $this->leave,
                'quarterLabel' => $this->quarterLabel(),
                'approvedVlLabel' => $this->daysLabel((string) ($this->usage['taken'] ?? '0')),
                'excessLabel' => $this->daysLabel($this->excessDays()),
                'latestLeaveDates' => $this->latestLeaveDates(),
                'durationLabel' => $this->durationLabel(),
                'frontendUrl' => config('leave.frontend_url'),
            ],
        );
    }

    private function quarterLabel(): string
    {
        $quarter = (string) ($this->usage['quarter'] ?? '');

        if (preg_match('/^(\d{4})-Q([1-4])$/', $quarter, $matches) === 1) {
            return 'Q'.$matches[2].' '.$matches[1];
        }

        return $quarter !== '' ? $quarter : 'this quarter';
    }

    private function excessDays(): string
    {
        $taken = (string) ($this->usage['taken'] ?? '0');
        $cap = (string) ($this->usage['cap'] ?? '0');
        $excess = bcsub($taken, $cap, 2);

        if (bccomp($excess, '0', 2) === -1) {
            return '0.00';
        }

        return $excess;
    }

    private function daysLabel(string $raw): string
    {
        $value = rtrim(rtrim(number_format((float) $raw, 2, '.', ''), '0'), '.') ?: '0';
        $unit = ((float) $value == 1.0 || (float) $value < 1.0) ? 'day' : 'days';

        return $value.' '.$unit;
    }

    private function latestLeaveDates(): string
    {
        $start = $this->leave->start_date;
        $end = $this->leave->end_date;

        if ($start->isSameDay($end)) {
            return $start->format('F j, Y');
        }

        if ($start->isSameMonth($end)) {
            return $start->format('F j').' – '.$end->format('j, Y');
        }

        if ($start->isSameYear($end)) {
            return $start->format('F j').' – '.$end->format('F j, Y');
        }

        return $start->format('F j, Y').' – '.$end->format('F j, Y');
    }

    private function durationLabel(): string
    {
        $label = $this->daysLabel((string) $this->leave->duration);
        $period = $this->leave->day_type?->periodLabel();

        if ($period !== null) {
            $label .= ' ('.$period.')';
        }

        return $label;
    }
}
