<?php

namespace App\Mail\Leave;

use Symfony\Component\Mime\Email;

/**
 * Embeds header/status PNGs as inline CID parts so Blade mail components
 * can reference cid:book-open / cid:status-icon without $message->embed().
 */
trait EmbedsLeaveMailIcons
{
    protected function embedLeaveMailIcons(string $statusIcon): void
    {
        $this->withSymfonyMessage(function (Email $message) use ($statusIcon): void {
            $logoPath = public_path('book-open.png');
            if (is_file($logoPath)) {
                $message->embedFromPath($logoPath, 'book-open');
            }

            $iconPath = public_path('status-icons/'.$statusIcon);
            if (is_file($iconPath)) {
                $message->embedFromPath($iconPath, 'status-icon');
            }
        });
    }
}
