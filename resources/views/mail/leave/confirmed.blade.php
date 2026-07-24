<x-mail::message>
# Your leave request was approved

Hello {{ $recipientName }},

The **{{ $leave->leaveType->type }}** request for **{{ $employeeName }}** has been approved by **{{ $approverName }}**.

**Dates:** {{ $leave->start_date->format('M j, Y') }} – {{ $leave->end_date->format('M j, Y') }}  
**Duration:** {{ $leave->duration }} day(s)  
**Reason:** {{ $leave->leave_reason }}

@if($frontendUrl)
<x-mail::button :url="$frontendUrl">
View leave details
</x-mail::button>
@endif

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
