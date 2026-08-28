<x-mail::message>
@include('mail.leave._heading', [
    'title' => $leave->leaveType->type.' request awaiting your approval',
    'icon' => 'pending-conversion.png',
    'circle' => '#fff3e8',
])

Hello {{ $adminName }},

**{{ $employeeName }}** submitted a **{{ $leave->leaveType->type }}** request that is awaiting your approval.

@include('mail.leave._details', ['leave' => $leave])

@if ($attachmentSentence)
{{ $attachmentSentence }}
@endif

Please review the leave request and submit your decision.

@if ($frontendUrl)
<x-mail::button :url="$frontendUrl">
View leave request
</x-mail::button>
@endif

@include('mail.leave._automated')
</x-mail::message>
