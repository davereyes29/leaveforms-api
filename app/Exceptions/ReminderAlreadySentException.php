<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpKernel\Exception\HttpException;

class ReminderAlreadySentException extends HttpException
{
    public function __construct(
        public readonly ?string $lastSentAt,
        string $message = 'A reminder was already sent today.',
    ) {
        parent::__construct(429, $message);
    }

    /**
     * @return JsonResponse
     */
    public function render()
    {
        return response()->json([
            'message' => $this->getMessage(),
            'last_sent_at' => $this->lastSentAt,
        ], 429);
    }
}
