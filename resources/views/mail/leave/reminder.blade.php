<x-mail::message>
# Reminder: leave request pending your approval

Hello {{ $approverName }},

**{{ $employeeName }}** filed a **{{ $leave->leaveType->type }}** request that still needs your review.

**Dates:** {{ $leave->start_date->format('M j, Y') }} – {{ $leave->end_date->format('M j, Y') }}  
**Duration:** {{ $leave->duration }} day(s)  
**Reason:** {{ $leave->leave_reason }}

@if($frontendUrl)
<x-mail::button :url="$frontendUrl">
View pending approvals
</x-mail::button>
@endif

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
