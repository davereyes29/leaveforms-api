<x-mail::message>
@include('mail.leave._heading', [
    'status' => 'Notice',
    'message' => 'Impending quarterly VL memo',
    'tone' => 'pending',
])

Hello {{ $adminName }},

**{{ $employeeName }}** would exceed **{{ $usage['cap'] }} VL days** in **{{ $usage['quarter'] }}** if their pending Vacation Leave is approved. This is an impending memo notice.

@include('mail.leave._kv_table', [
    'heading' => 'Quarter summary',
    'rows' => [
        ['label' => 'Employee', 'value' => $employeeName],
        ['label' => 'Quarter', 'value' => $usage['quarter']],
        ['label' => 'Confirmed VL this quarter', 'value' => $usage['taken'].' day(s)'],
        ['label' => 'Projected if approved', 'value' => $usage['projected'].' day(s)'],
        ['label' => 'Quarterly cap', 'value' => $usage['cap'].' day(s)'],
    ],
])

@if ($frontendUrl)
<x-mail::button :url="$frontendUrl">
Open Leave Forms
</x-mail::button>
@endif

@include('mail.leave._automated')
</x-mail::message>
