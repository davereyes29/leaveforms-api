<x-mail::message>
@include('mail.leave._heading', [
    'status' => 'Exceeded',
    'message' => 'Quarterly VL memo — cap exceeded',
    'tone' => 'approved',
])

Hello {{ $adminName }},

**{{ $employeeName }}** has now exceeded **{{ $usage['cap'] }} VL days** in **{{ $usage['quarter'] }}**. They currently have **{{ $usage['taken'] }} confirmed VL day(s)** against the quarterly cap. A memo may be issued.

@include('mail.leave._kv_table', [
    'heading' => 'Quarter summary',
    'rows' => [
        ['label' => 'Employee', 'value' => $employeeName],
        ['label' => 'Quarter', 'value' => $usage['quarter']],
        ['label' => 'Confirmed VL this quarter', 'value' => $usage['taken'].' day(s)'],
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
