<x-mail::message>
@include('mail.leave._heading', [
    'status' => 'Approved',
    'message' => 'Your leave conversion request has been approved',
    'tone' => 'approved',
])

Hello {{ $recipientName }},

Your request to convert your **{{ $leave->leaveType->mailLabel() }}** to **{{ $conversion->toLeaveType->mailLabel() }}** has been approved by **{{ $adminName }}**.

Your leave is now recorded as **{{ $conversion->toLeaveType->mailLabel() }}**.

@include('mail.leave._details', [
    'leave' => $leave,
    'heading' => 'Conversion Details',
    'showReason' => false,
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
