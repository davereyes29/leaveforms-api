<x-mail::message>
@include('mail.leave._heading', [
    'title' => 'Reminder: Pending leave '.($pendingCount === 1 ? 'request' : 'requests'),
    'icon' => 'reminder.png',
    'circle' => '#e0f2f1',
])

Hello {{ $approverName }},

You have **{{ $pendingCount }}** leave {{ $pendingCount === 1 ? 'request' : 'requests' }} awaiting your approval.

Please review the pending requests and submit your decisions when available.

@if ($frontendUrl)
<x-mail::button :url="$frontendUrl">
View pending approvals
</x-mail::button>
@endif

@include('mail.leave._automated')
</x-mail::message>
