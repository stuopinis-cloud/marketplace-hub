<?php

namespace App\Services\Marketplace\Translations;

use RuntimeException;

class OpenAiRateLimitException extends RuntimeException
{
    public function __construct(
        string $message = 'OpenAI translation rate limited (HTTP 429).',
        public readonly int $retryAfterSeconds = 60,
        public readonly bool $isLocal = false,
        int $code = 429,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $code, $previous);
    }
}
