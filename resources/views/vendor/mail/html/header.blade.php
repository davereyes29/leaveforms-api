@props(['url'])
<tr>
<td class="header" align="left" style="padding: 24px 32px 16px; text-align: left;">
<table width="100%" cellpadding="0" cellspacing="0" role="presentation" style="width: 100%;">
<tr>
<td style="vertical-align: middle;">
{{-- Nested auto-width table: keeps original middle alignment, prevents Outlook stretching the icon column --}}
<table cellpadding="0" cellspacing="0" role="presentation">
<tr>
<td style="padding-right: 8px; vertical-align: middle;">
<img src="cid:book-open" width="24" height="24" alt="" style="display: block; border: 0; outline: none; text-decoration: none; width: 24px; height: 24px; max-width: 24px;">
</td>
<td style="vertical-align: middle;">
<a href="{{ $url }}" class="email-brand" style="display: inline-block; color: #009688; font-size: 20px; font-weight: 700; line-height: 1.3; text-decoration: none;">
{!! $slot !!}
</a>
</td>
</tr>
</table>
</td>
@if (config('leave.notifications.demo_label', true))
<td align="right" style="vertical-align: middle; text-align: right; white-space: nowrap; padding-left: 12px;">
<span class="email-demo-label" style="color: #dc3545; font-size: 12px; font-weight: 700; line-height: 1.3; text-transform: uppercase;">
FOR DEMO
</span>
</td>
@endif
</tr>
</table>
</td>
</tr>
{{-- Single Outlook-safe divider (replaces border-bottom on header) --}}
<tr>
<td style="padding: 0; font-size: 0; line-height: 0; height: 1px; background-color: #d9dde5;">&nbsp;</td>
</tr>
