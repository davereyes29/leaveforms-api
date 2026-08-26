<x-mail::message>
@include('mail.leave._heading', [
    'title' => 'Your leave request has been approved',
    'icon' => 'approved.png',
    'circle' => '#e8f5ec',
])

Hello {{ $recipientName }},

Your **{{ $leave->leaveType->type }}** request has been approved by **{{ $approverName }}**.

@include('mail.leave._details', ['leave' => $leave])

@if ($frontendUrl)
<x-mail::button :url="$frontendUrl">
View leave request
</x-mail::button>
@endif

@include('mail.leave._automated')
</x-mail::message>
