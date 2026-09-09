<x-mail::message>
@include('mail.leave._heading', [
    'title' => 'Quarterly VL memo withdrawn',
    'icon' => 'cancelled.png',
    'circle' => '#fdecea',
])

Hello {{ $adminName }},

The impending quarterly VL notice for **{{ $employeeName }}** in **{{ $usage['quarter'] }}** no longer applies. The pending Vacation Leave that would have taken them over **{{ $usage['cap'] }} VL days** was **{{ $reasonLabel }}**.

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
