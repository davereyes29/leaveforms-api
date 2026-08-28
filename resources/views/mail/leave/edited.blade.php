<x-mail::message>
@include('mail.leave._heading', [
    'title' => 'Leave request updated — approval required',
    'icon' => 'pending.png',
    'circle' => '#fff8e1',
])

Hello {{ $approverName }},

**{{ $employeeName }}** updated their **{{ $leave->leaveType->mailLabel() }}** request. The updated request is awaiting your approval.

@include('mail.leave._details', ['leave' => $leave])

@if ($frontendUrl)
<x-mail::button :url="$frontendUrl">
View leave request
</x-mail::button>
@endif

@include('mail.leave._automated')
</x-mail::message>
