<x-mail::message>
@include('mail.leave._heading', [
    'title' => 'Leave request awaiting your approval',
    'icon' => 'pending.png',
    'circle' => '#fff8e1',
])

Hello {{ $approverName }},

**{{ $employeeName }}** submitted a **{{ $leave->leaveType->mailLabel() }}** request that is awaiting your approval.

@include('mail.leave._details', ['leave' => $leave])

@if ($frontendUrl)
<x-mail::button :url="$frontendUrl">
View leave request
</x-mail::button>
@endif

@include('mail.leave._automated')
</x-mail::message>
