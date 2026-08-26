@props(['url'])
<tr>
<td class="header" align="left" style="padding: 24px 32px 16px; text-align: left; border-bottom: 1px solid #d9dde5;">
<table cellpadding="0" cellspacing="0" role="presentation">
<tr>
<td style="padding-right: 10px; vertical-align: middle;">
<img src="{{ asset('book-open.png') }}" width="24" height="24" alt="" style="display: block; border: 0; outline: none; text-decoration: none;">
</td>
<td style="vertical-align: middle;">
<a href="{{ $url }}" class="email-brand" style="display: inline-block; color: #009688; font-size: 20px; font-weight: 700; line-height: 1.3; text-decoration: none;">
{!! $slot !!}
</a>
</td>
</tr>
</table>
</td>
</tr>
