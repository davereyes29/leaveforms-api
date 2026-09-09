<x-mail::message>
@include('mail.leave._heading', [
    'status' => 'Reminder',
    'message' => $leave->leaveType->mailLabel().' request awaiting your approval',
    'tone' => 'reminder',
])

Hello {{ $approverName }},

This is a reminder that **{{ $employeeName }}'s {{ $leave->leaveType->mailLabel() }}** request is still awaiting your approval.

@include('mail.leave._details', ['leave' => $leave])

@if ($frontendUrl)
<x-mail::button :url="$frontendUrl">
View leave request
</x-mail::button>
@endif

@include('mail.leave._automated')
</x-mail::message>
