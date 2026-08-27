<x-mail::message>
@include('mail.leave._heading', [
    'title' => 'Your leave conversion request has been approved',
    'icon' => 'approved.png',
    'circle' => '#e8f5ec',
])

Hello {{ $recipientName }},

Your request to convert your **{{ $leave->leaveType->type }}** to **{{ $conversion->toLeaveType->type }}** has been approved by **{{ $adminName }}**.

Your leave is now recorded as **{{ $conversion->toLeaveType->type }}**.

@include('mail.leave._details', [
    'leave' => $leave,
    'heading' => 'Conversion Details',
    'showReason' => false,
    'before' => [
        ['label' => 'From', 'value' => $conversion->fromLeaveType->type],
        ['label' => 'To', 'value' => $conversion->toLeaveType->type],
    ],
])

@if ($frontendUrl)
<x-mail::button :url="$frontendUrl">
View leave request
</x-mail::button>
@endif

@include('mail.leave._automated')
</x-mail::message>
