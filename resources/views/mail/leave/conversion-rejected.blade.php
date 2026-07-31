<x-mail::message>
# Your VL → SL conversion was rejected

Hello {{ $recipientName }},

Your conversion request for **{{ $employeeName }}** has been rejected by **{{ $adminName }}**. The leave remains Vacation Leave.

**Dates:** {{ $leave->start_date->format('M j, Y') }} – {{ $leave->end_date->format('M j, Y') }}  
**Duration:** {{ $leave->duration }} day(s)  
**Rejection remarks:** {{ $remarks }}

@if($frontendUrl)
<x-mail::button :url="$frontendUrl">
View leave details
</x-mail::button>
@endif

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
