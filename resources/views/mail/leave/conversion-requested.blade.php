<x-mail::message>
# VL → SL conversion pending your review

Hello {{ $adminName }},

**{{ $employeeName }}** requested conversion of a confirmed leave from **{{ $conversion->fromLeaveType->type }}** to **{{ $conversion->toLeaveType->type }}**.

The medical certificate is attached to this email. Designated approvers are CC’d for awareness.

**Dates:** {{ $leave->start_date->format('M j, Y') }} – {{ $leave->end_date->format('M j, Y') }}  
**Duration:** {{ $leave->duration }} day(s)  
**Reason:** {{ $leave->leave_reason }}

@if($frontendUrl)
<x-mail::button :url="$frontendUrl">
Review conversion
</x-mail::button>
@endif

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
