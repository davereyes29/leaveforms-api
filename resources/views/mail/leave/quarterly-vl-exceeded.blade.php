<x-mail::message>
@include('mail.leave._heading', [
    'status' => 'Quarterly VL Limit Reminder',
    'message' => 'Approved Vacation Leave exceeds the quarterly limit',
    'tone' => 'pending',
])

Hello {{ $adminName }},

**{{ $employeeName }}**'s approved leave records for **{{ $quarterLabel }}** have exceeded the quarterly VL limit.

@include('mail.leave._kv_table', [
    'heading' => 'Leave Summary',
    'rows' => [
        ['label' => 'Quarter', 'value' => $quarterLabel],
        ['label' => 'Approved VL', 'value' => $approvedVlLabel],
        ['label' => 'Excess', 'value' => $excessLabel],
        ['label' => 'Latest Approved Leave', 'value' => $latestLeaveDates],
        ['label' => 'Duration', 'value' => $durationLabel],
    ],
])

For management monitoring only. Any further action is subject to Management’s assessment.

@if ($frontendUrl)
<x-mail::button :url="$frontendUrl">
View leave record
</x-mail::button>
@endif

@include('mail.leave._automated')
</x-mail::message>
