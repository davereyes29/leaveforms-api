<x-mail::message>
@include('mail.leave._heading', [
    'title' => 'Your leave conversion request has been rejected',
    'icon' => 'rejected.png',
    'circle' => '#fdecea',
])

Hello {{ $recipientName }},

Your request to convert your **{{ $conversion->fromLeaveType->mailLabel() }}** to **{{ $conversion->toLeaveType->mailLabel() }}** has been rejected by **{{ $adminName }}**.

Your leave will remain recorded as **{{ $conversion->fromLeaveType->mailLabel() }}**.

@include('mail.leave._details', [
    'leave' => $leave,
    'heading' => 'Conversion Details',
    'showDuration' => false,
    'showReason' => false,
    'extra' => filled($remarks) ? [
        ['label' => 'Reason for Rejection', 'value' => $remarks],
    ] : [],
    'before' => [
        ['label' => 'From', 'value' => $conversion->fromLeaveType->mailLabel()],
        ['label' => 'To', 'value' => $conversion->toLeaveType->mailLabel()],
    ],
])

@if ($frontendUrl)
<x-mail::button :url="$frontendUrl">
View leave request
</x-mail::button>
@endif

@include('mail.leave._automated')
</x-mail::message>
