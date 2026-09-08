<x-mail::message>
@include('mail.leave._heading', [
    'status' => 'Approved',
    'message' => 'Your '.$leave->leaveType->mailLabel().' request has been approved',
    'tone' => 'approved',
])

Hello {{ $recipientName }},

Your **{{ $leave->leaveType->mailLabel() }}** request has been approved by **{{ $approverName }}**.

@include('mail.leave._details', ['leave' => $leave])

@if ($frontendUrl)
<x-mail::button :url="$frontendUrl">
View leave request
</x-mail::button>
@endif

@include('mail.leave._automated')
</x-mail::message>
