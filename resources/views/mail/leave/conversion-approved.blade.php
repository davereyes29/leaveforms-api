<x-mail::message>
# Your VL → SL conversion was approved

Hello {{ $recipientName }},

Your conversion request for **{{ $employeeName }}** has been approved by **{{ $adminName }}**. The leave is now recorded as **{{ $leave->leaveType->type }}**.

**Dates:** {{ $leave->start_date->format('M j, Y') }} – {{ $leave->end_date->format('M j, Y') }}  
**Duration:** {{ $leave->duration }} day(s)

@if($frontendUrl)
<x-mail::button :url="$frontendUrl">
View leave details
</x-mail::button>
@endif

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
