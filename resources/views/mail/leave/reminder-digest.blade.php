<x-mail::message>
# Reminder: pending leave {{ $pendingCount === 1 ? 'request' : 'requests' }}

Hello {{ $approverName }},

You have **{{ $pendingCount }}** pending leave {{ $pendingCount === 1 ? 'request' : 'requests' }} that still {{ $pendingCount === 1 ? 'needs' : 'need' }} your approval.

@if($frontendUrl)
<x-mail::button :url="$frontendUrl">
View pending approvals
</x-mail::button>
@endif

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
