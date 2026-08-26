{{-- Shared leave details: Dates / Duration / Reason (+ optional before/extra rows) --}}
@php
    $showReason = $showReason ?? true;
    $showDuration = $showDuration ?? true;
    $heading = $heading ?? 'Leave Details';
    $before = $before ?? [];
    $extra = $extra ?? [];

    $rows = $before;
    $rows[] = [
        'label' => 'Dates',
        'value' => $leave->start_date->format('M j, Y').' – '.$leave->end_date->format('M j, Y'),
    ];
    if ($showDuration) {
        $rows[] = [
            'label' => 'Duration',
            'value' => $leave->duration.' day(s)',
        ];
    }
    if ($showReason) {
        $rows[] = [
            'label' => 'Reason',
            'value' => $leave->leave_reason,
        ];
    }
    $rows = array_merge($rows, $extra);
    $lastIndex = count($rows) - 1;
@endphp
@if ($heading)
<h3 class="email-section-title">{{ $heading }}</h3>
@endif
<table class="email-details" width="100%" cellpadding="0" cellspacing="0" role="presentation">
@foreach ($rows as $index => $row)
<tr class="email-details-row{{ $index === $lastIndex ? ' email-details-row-last' : '' }}">
<td class="email-label" align="left" valign="top" style="border-bottom: {{ $index === $lastIndex ? 'none' : '1px solid #d9dde5' }}; padding: 12px 16px; color: #212529; font-size: 14px; font-weight: 700; text-align: left; width: 20%;">
{{ $row['label'] }}
</td>
<td class="email-value" align="left" valign="top" style="border-bottom: {{ $index === $lastIndex ? 'none' : '1px solid #d9dde5' }}; padding: 12px 16px; color: #212529; font-size: 14px; font-weight: 500; text-align: left;">
{{ $row['value'] }}
</td>
</tr>
@endforeach
</table>
