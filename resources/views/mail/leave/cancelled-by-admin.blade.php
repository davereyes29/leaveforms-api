<x-mail::message>
@include('mail.leave._heading', [
    'status' => 'Cancelled',
    'message' => 'Your '.$leave->leaveType->mailLabel().' request has been cancelled',
    'tone' => 'cancelled',
])

Hello {{ $recipientName }},

Your **{{ $leave->leaveType->mailLabel() }}** request has been cancelled by **{{ $adminName }}**.

@include('mail.leave._details', [
    'leave' => $leave,
    'showDuration' => false,
    'showReason' => false,
    'extra' => [
        ['label' => 'Reason for Cancellation', 'value' => $cancellationReason],
    ],
])

@if ($frontendUrl)
<x-mail::button :url="$frontendUrl">
View leave request
</x-mail::button>
@endif

@include('mail.leave._automated')
</x-mail::message>
