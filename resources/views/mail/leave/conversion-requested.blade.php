<x-mail::message>
@include('mail.leave._heading', [
    'title' => 'Leave conversion request awaiting your review',
    'icon' => 'pending-conversion.png',
    'circle' => '#fff3e8',
])

Hello {{ $adminName }},

**{{ $employeeName }}** submitted a request to convert their approved **{{ $conversion->fromLeaveType->type }}** to **{{ $conversion->toLeaveType->type }}**.

@include('mail.leave._details', [
    'leave' => $leave,
    'heading' => 'Conversion details',
    'before' => [
        ['label' => 'From', 'value' => $conversion->fromLeaveType->type],
        ['label' => 'To', 'value' => $conversion->toLeaveType->type],
    ],
])

A medical certificate is attached for your review.

Please review the conversion request and submit your decision.

@if ($frontendUrl)
<x-mail::button :url="$frontendUrl">
Review conversion request
</x-mail::button>
@endif

@include('mail.leave._automated')
</x-mail::message>
