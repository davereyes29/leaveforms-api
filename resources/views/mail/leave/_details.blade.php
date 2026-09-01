{{-- Shared leave details: Dates / Duration / Reason (+ optional before/extra rows) --}}
@php
    $showReason = $showReason ?? true;
    $showDuration = $showDuration ?? true;
    $heading = $heading ?? 'Leave Details';
    $before = $before ?? [];
    $extra = $extra ?? [];

    $start = $leave->start_date;
    $end = $leave->end_date;

    if ($start->isSameDay($end)) {
        $datesValue = $start->format('F j, Y');
    } elseif ($start->isSameMonth($end)) {
        // e.g. August 27 – 29, 2026
        $datesValue = $start->format('F j').' – '.$end->format('j, Y');
    } elseif ($start->isSameYear($end)) {
        // e.g. August 27 – September 2, 2026
        $datesValue = $start->format('F j').' – '.$end->format('F j, Y');
    } else {
        // e.g. December 30, 2025 – January 2, 2026
        $datesValue = $start->format('F j, Y').' – '.$end->format('F j, Y');
    }

    $rows = $before;
    $rows[] = [
        'label' => 'Dates',
        'value' => $datesValue,
    ];
    if ($showDuration) {
        $durationRaw = number_format((float) $leave->duration, 2, '.', '');
        $durationValue = rtrim(rtrim($durationRaw, '0'), '.') ?: '0';
        $durationUnit = ((float) $durationValue == 1.0 || (float) $durationValue < 1.0)
            ? 'day'
            : 'days';

        $durationText = $durationValue.' '.$durationUnit;
        $period = $leave->day_type?->periodLabel();

        if ($period !== null) {
            $durationText .= ' ('.$period.')';
        }

        $rows[] = [
            'label' => 'Duration',
            'value' => $durationText,
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
<h3 class="email-section-title" style="margin: 24px 0 0; padding-bottom: 12px; color: #212529; font-size: 14px; font-weight: 700; text-align: left;">{{ $heading }}</h3>
@endif
{{-- White wrapper + padding-bottom for Outlook-safe gap (avoids spacer-row bgcolor bleed) --}}
<table width="100%" cellpadding="0" cellspacing="0" role="presentation" bgcolor="#ffffff" style="width: 100%; background-color: #ffffff; border: 0;">
<tr>
<td bgcolor="#ffffff" style="padding: 0 0 16px 0; background-color: #ffffff;">
<table class="email-details" width="100%" cellpadding="0" cellspacing="0" role="presentation" bgcolor="#f4f5f7" style="margin: 0; width: 100%; background-color: #f4f5f7; border: 1px solid #d9dde5;">
@foreach ($rows as $index => $row)
<tr class="email-details-row{{ $index === $lastIndex ? ' email-details-row-last' : '' }}">
<td class="email-label" align="left" valign="top" bgcolor="#f4f5f7" style="border-bottom: {{ $index === $lastIndex ? 'none' : '1px solid #d9dde5' }}; padding: 12px 16px; color: #212529; font-size: 14px; font-weight: 700; text-align: left; width: 20%; background-color: #f4f5f7;">
{{ $row['label'] }}
</td>
<td class="email-value" align="left" valign="top" bgcolor="#f4f5f7" style="border-bottom: {{ $index === $lastIndex ? 'none' : '1px solid #d9dde5' }}; padding: 12px 16px; color: #212529; font-size: 14px; font-weight: 500; text-align: left; background-color: #f4f5f7;">
{{ $row['value'] }}
</td>
</tr>
@endforeach
</table>
</td>
</tr>
</table>
