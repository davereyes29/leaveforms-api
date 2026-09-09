<x-mail::message>
@include('mail.leave._heading', [
    'status' => 'Pending Approval',
    'message' => $leave->leaveType->mailLabel().' request awaiting your approval',
    'tone' => 'pending',
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
