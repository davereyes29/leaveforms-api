@php
    $heading = $heading ?? 'Quarter summary';
    $labelWidth = $labelWidth ?? '20%';
    $rows = $rows ?? [];
    $lastIndex = count($rows) - 1;
@endphp
@if ($heading)
<h3 class="email-section-title" style="margin: 24px 0 0; padding-bottom: 12px; color: #212529; font-size: 14px; font-weight: 700; text-align: left;">{{ $heading }}</h3>
@endif
<table width="100%" cellpadding="0" cellspacing="0" role="presentation" bgcolor="#ffffff" style="width: 100%; background-color: #ffffff; border: 0;">
<tr>
<td bgcolor="#ffffff" style="padding: 0 0 16px 0; background-color: #ffffff;">
<table class="email-details" width="100%" cellpadding="0" cellspacing="0" role="presentation" bgcolor="#f4f5f7" style="margin: 0; width: 100%; background-color: #f4f5f7; border: 1px solid #d9dde5;">
@foreach ($rows as $index => $row)
<tr class="email-details-row{{ $index === $lastIndex ? ' email-details-row-last' : '' }}">
<td class="email-label" align="left" valign="top" bgcolor="#f4f5f7" style="border-bottom: {{ $index === $lastIndex ? 'none' : '1px solid #d9dde5' }}; padding: 12px 16px; color: #212529; font-size: 14px; font-weight: 700; text-align: left; width: {{ $labelWidth }}; background-color: #f4f5f7;">
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
