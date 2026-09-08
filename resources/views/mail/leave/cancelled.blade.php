<x-mail::message>
@include('mail.leave._heading', [
    'status' => 'Cancelled',
    'message' => $leave->leaveType->mailLabel().' request cancelled',
    'tone' => 'cancelled',
])

Hello {{ $approverName }},

**{{ $employeeName }}** cancelled their **{{ $leave->leaveType->mailLabel() }}** request.

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
