<x-mail::message>
@include('mail.leave._heading', [
    'status' => 'Approved',
    'message' => $forFiler
        ? $employeeName."'s ".$leave->leaveType->mailLabel().' request has been approved'
        : 'Your '.$leave->leaveType->mailLabel().' request has been approved',
    'tone' => 'approved',
])

Hello {{ $recipientName }},

@if ($forFiler)
The **{{ $leave->leaveType->mailLabel() }}** request you filed for **{{ $employeeName }}** has been approved by **{{ $approverName }}**.
@else
Your **{{ $leave->leaveType->mailLabel() }}** request has been approved by **{{ $approverName }}**.
@endif

@include('mail.leave._details', ['leave' => $leave])

@if ($frontendUrl)
<x-mail::button :url="$frontendUrl">
View leave request
</x-mail::button>
@endif

@include('mail.leave._automated')
</x-mail::message>
