<x-mail::message>
@include('mail.leave._heading', [
    'status' => 'Pending Conversion',
    'message' => 'Leave conversion request awaiting your review',
    'tone' => 'pending-conversion',
])

Hello {{ $adminName }},

**{{ $employeeName }}** submitted a request to convert their approved **{{ $conversion->fromLeaveType->mailLabel() }}** to **{{ $conversion->toLeaveType->mailLabel() }}**.

@include('mail.leave._details', [
    'leave' => $leave,
    'heading' => 'Conversion details',
    'before' => [
        ['label' => 'From', 'value' => $conversion->fromLeaveType->mailLabel()],
        ['label' => 'To', 'value' => $conversion->toLeaveType->mailLabel()],
        ['label' => 'Conversion reason', 'value' => $conversion->reason],
    ],
])

Supporting documentation is attached for your review.

Please review the conversion request and submit your decision.

@if ($frontendUrl)
<x-mail::button :url="$frontendUrl">
Review conversion request
</x-mail::button>
@endif

@include('mail.leave._automated')
</x-mail::message>
