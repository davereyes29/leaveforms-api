<x-mail::message>
@include('mail.leave._heading', [
    'status' => 'Rejected',
    'message' => $forFiler
        ? $employeeName."'s ".$leave->leaveType->mailLabel().' request has been rejected'
        : 'Your '.$leave->leaveType->mailLabel().' request has been rejected',
    'tone' => 'rejected',
])

Hello {{ $recipientName }},

@if ($forFiler)
The **{{ $leave->leaveType->mailLabel() }}** request you filed for **{{ $employeeName }}** has been rejected by **{{ $approverName }}**.
@else
Your **{{ $leave->leaveType->mailLabel() }}** request has been rejected by **{{ $approverName }}**.
@endif

@include('mail.leave._details', [
    'leave' => $leave,
    'showDuration' => false,
    'showReason' => false,
    'extra' => filled($remarks) ? [
        ['label' => 'Reason for Rejection', 'value' => $remarks],
    ] : [],
])

@if ($frontendUrl)
<x-mail::button :url="$frontendUrl">
View leave request
</x-mail::button>
@endif

@include('mail.leave._automated')
</x-mail::message>
