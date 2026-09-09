<x-mail::message>
@include('mail.leave._heading', [
    'status' => 'Quarterly Leave Limit Reminder',
    'message' => 'Approved Vacation Leave exceeds the quarterly limit',
    'tone' => 'pending',
    'iconPadding' => '10px',
])

Hello {{ $adminName }},

**{{ $employeeName }}**'s approved Vacation Leave records for **{{ $quarterLabel }}** have exceeded the quarterly VL limit.

@include('mail.leave._kv_table', [
    'heading' => 'Leave Summary',
    'labelWidth' => '30%',
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
