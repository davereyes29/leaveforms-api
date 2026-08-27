{{-- Status icon + title beside each other --}}
@php
    $circleSize = 36;
    $iconSize = 26;
@endphp
<table class="email-heading" width="100%" cellpadding="0" cellspacing="0" role="presentation" style="margin: 0 0 16px;">
<tr>
<td width="{{ $circleSize }}" valign="middle" style="width: {{ $circleSize }}px; vertical-align: middle;">
<table cellpadding="0" cellspacing="0" role="presentation">
<tr>
<td class="email-heading-icon" align="center" valign="middle" width="{{ $circleSize }}" height="{{ $circleSize }}" bgcolor="{{ $circle }}" style="width: {{ $circleSize }}px; height: {{ $circleSize }}px; background-color: {{ $circle }}; border-radius: 50%; text-align: center; vertical-align: middle; line-height: {{ $circleSize }}px;">
<img src="cid:status-icon" width="{{ $iconSize }}" height="{{ $iconSize }}" alt="" style="display: block; border: 0; outline: none; text-decoration: none;">
</td>
</tr>
</table>
</td>
<td valign="middle" style="padding-left: 12px; vertical-align: middle;">
<h1 class="email-heading-title" style="margin: 0; color: #212529; font-size: 20px; font-weight: 700; line-height: 1.3; text-align: left;">{{ $title }}</h1>
</td>
</tr>
</table>
