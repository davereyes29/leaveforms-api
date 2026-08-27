@props([
    'url',
    'color' => 'primary',
    'align' => 'center',
])
@php
    $buttonColors = [
        'primary' => '#009688',
        'blue' => '#009688',
        'success' => '#198754',
        'green' => '#198754',
        'error' => '#dc3545',
        'red' => '#dc3545',
    ];
    $buttonBg = $buttonColors[$color] ?? '#009688';
    $buttonLabel = trim(strip_tags($slot));
    // ~6px radius on 40px height ≈ 15% arcsize for Outlook VML
    $buttonHeight = 40;
    // Match pre-Task-5 side padding (16px); size VML to label so it is not forced wide
    $buttonWidth = max(140, (int) round(mb_strlen($buttonLabel) * 7.2) + 32);
@endphp
<table class="action" align="{{ $align }}" width="100%" cellpadding="0" cellspacing="0" role="presentation" bgcolor="#ffffff" style="margin: 8px auto 20px; padding: 0; text-align: center; width: 100%; background-color: #ffffff;">
<tr>
<td align="{{ $align }}" bgcolor="#ffffff" style="background-color: #ffffff;">
<!--[if mso]>
<v:roundrect xmlns:v="urn:schemas-microsoft-com:vml" xmlns:w="urn:schemas-microsoft-com:office:word" href="{{ $url }}" style="height:{{ $buttonHeight }}px;v-text-anchor:middle;width:{{ $buttonWidth }}px;" arcsize="15%" strokecolor="{{ $buttonBg }}" fillcolor="{{ $buttonBg }}">
<w:anchorlock/>
<center style="color:#ffffff;font-family:Arial,Helvetica,sans-serif;font-size:14px;font-weight:600;">{{ $buttonLabel }}</center>
</v:roundrect>
<![endif]-->
<!--[if !mso]><!-->
<a href="{{ $url }}" class="button button-{{ $color }}" target="_blank" rel="noopener" style="background-color: {{ $buttonBg }}; border-radius: 6px; color: #ffffff; display: inline-block; font-family: Arial, Helvetica, sans-serif; font-size: 14px; font-weight: 600; line-height: {{ $buttonHeight }}px; padding: 0 16px; text-decoration: none; -webkit-text-size-adjust: none; mso-hide: all;">{{ $buttonLabel }}</a>
<!--<![endif]-->
</td>
</tr>
</table>
