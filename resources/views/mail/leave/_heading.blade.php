{{--
  Status header: soft filled box with icon + STATUS + descriptive message.
  Icons are embedded as cid:status-icon by EmbedsLeaveMailIcons.
--}}
@php
    $tone = $tone ?? 'pending';
    $iconPadding = $iconPadding ?? '2px';
    $tones = [
        'pending' => [
            'bg' => '#fff8e1',
            'border' => '#ffe08a',
            'color' => '#9a6700',
        ],
        'pending-conversion' => [
            'bg' => '#fff3e8',
            'border' => '#ffd0a8',
            'color' => '#b35c00',
        ],
        'approved' => [
            'bg' => '#e8f5ec',
            'border' => '#c3e6cb',
            'color' => '#198754',
        ],
        'rejected' => [
            'bg' => '#fdecea',
            'border' => '#f5c2c7',
            'color' => '#dc3545',
        ],
        'cancelled' => [
            'bg' => '#f0f1f2',
            'border' => '#d9dde5',
            'color' => '#6c757d',
        ],
        'reminder' => [
            'bg' => '#eef5f7',
            'border' => '#cfe0e6',
            'color' => '#3d6b78',
        ],
    ];
    $palette = $tones[$tone] ?? $tones['pending'];
    $bg = $palette['bg'];
    $border = $palette['border'];
    $statusColor = $palette['color'];
@endphp
<table class="email-status-box" width="100%" cellpadding="0" cellspacing="0" role="presentation" style="margin: 0 0 16px; width: 100%;">
<tr>
<td class="email-status-box-inner" bgcolor="{{ $bg }}" style="padding: 16px 18px; background-color: {{ $bg }}; border: 1px solid {{ $border }}; border-radius: 8px;">
<table cellpadding="0" cellspacing="0" role="presentation" style="width: 100%;">
<tr>
<td style="padding-right: {{ $iconPadding }}; vertical-align: top;">
<img src="cid:status-icon"
      width="32" height="32" alt=""
       style="display:block;border:0;outline:none;text-decoration:none;width:32px;height:32px;max-width:32px;">
</td>
<td style="vertical-align: top;">
<div class="email-status-label" style="margin: 0 0 4px; color: {{ $statusColor }}; font-size: 20px; font-weight: 800; letter-spacing: 0.02em; line-height: 24px; mso-line-height-rule: exactly; text-transform: uppercase;">
{{ $status }}
</div>
<div class="email-status-message" style="margin: 0; color: #444c54; font-size: 15px; font-weight: 600; line-height: 20px;">
{{ $message }}
</div>
</td>
</tr>
</table>
</td>
</tr>
</table>
