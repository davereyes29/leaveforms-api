<x-mail::message>
@include('mail.leave._heading', [
    'title' => 'Reminder: Leave request awaiting your approval',
    'icon' => 'reminder.png',
    'circle' => '#e0f2f1',
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
