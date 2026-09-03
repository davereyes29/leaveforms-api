{{--
  Mirror vendor/mail/html/header.blade.php (book + KDT Leave Forms) cell-for-cell.
  24px icon matches the book mark size so Outlook row metrics stay the same.
--}}
@if (config('leave.notifications.demo_label', true))
<table class="email-demo-banner" width="100%" cellpadding="0" cellspacing="0" role="presentation" style="margin: 0 0 16px; width: 100%;">
<tr>
<td align="center" bgcolor="#fff3cd" style="padding: 10px 14px; background-color: #fff3cd; border: 1px solid #ffecb5; color: #856404; font-size: 13px; font-weight: 700; line-height: 1.4; text-align: center;">
FOR DEMO PURPOSES ONLY
</td>
</tr>
</table>
@endif
<table class="email-heading" cellpadding="0" cellspacing="0" role="presentation" style="margin: 0 0 16px;">
<tr>
<td style="padding-right: 10px; vertical-align: middle;">
<img src="cid:status-icon" width="24" height="24" alt="" style="display: block; border: 0; outline: none; text-decoration: none;">
</td>
<td style="vertical-align: middle;">
<a href="{{ config('leave.frontend_url') ?: config('app.url') }}" class="email-heading-title" style="display: inline-block; color: #212529; font-size: 20px; font-weight: 700; line-height: 1.3; text-decoration: none;">
{{ $title }}
</a>
</td>
</tr>
</table>
