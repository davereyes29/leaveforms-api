<x-mail::message>
@include('mail.leave._heading', [
    'title' => 'Your leave conversion request has been rejected',
    'icon' => 'rejected.png',
    'circle' => '#fdecea',
])

Hello {{ $recipientName }},

Your request to convert your **Vacation Leave** to **SICK LEAVE** has been rejected by **{{ $adminName }}**.

Your leave will remain recorded as **Vacation Leave**.

@include('mail.leave._details', [
    'leave' => $leave,
    'heading' => 'Conversion Details',
    'showDuration' => false,
    'showReason' => false,
    'extra' => filled($remarks) ? [
        ['label' => 'Reason for Rejection', 'value' => $remarks],
    ] : [],
])

@if ($frontendUrl)
<x-mail::button :url="$frontendUrl">
View leave request
</x-mail::button>
@endif

@include('mail.leave._automated')
</x-mail::message>
