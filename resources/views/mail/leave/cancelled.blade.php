<x-mail::message>
# Leave request was cancelled

Hello {{ $approverName }},

**{{ $employeeName }}** cancelled their **{{ $leave->leaveType->type }}** request. No further action is needed.

**Dates:** {{ $leave->start_date->format('M j, Y') }} – {{ $leave->end_date->format('M j, Y') }}  
**Duration:** {{ $leave->duration }} day(s)  
**Reason:** {{ $leave->leave_reason }}  
**Cancellation reason:** {{ $cancellationReason }}

@if($frontendUrl)
<x-mail::button :url="$frontendUrl">
View leave details
</x-mail::button>
@endif

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
