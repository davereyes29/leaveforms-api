<x-mail::message>
@include('mail.leave._heading', [
    'title' => $leave->leaveType->mailLabel().' request awaiting your approval',
    'icon' => 'pending-conversion.png',
    'circle' => '#fff3e8',
])

Hello {{ $adminName }},

**{{ $employeeName }}** submitted a **{{ $leave->leaveType->mailLabel() }}** request that is awaiting your approval.

@include('mail.leave._details', ['leave' => $leave])

Supporting documentation is attached for your review.

Please review the leave request and submit your decision.

@if ($frontendUrl)
<x-mail::button :url="$frontendUrl">
View leave request
</x-mail::button>
@endif

@include('mail.leave._automated')
</x-mail::message>
