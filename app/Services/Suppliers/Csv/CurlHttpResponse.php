<?php

namespace App\Services\Suppliers\Csv;

class CurlHttpResponse
{
    public function __construct(
        public readonly string $body,
        public readonly int $httpStatus,
        public readonly int $errno,
        public readonly string $error,
        public readonly ?string $contentType,
        public readonly int $responseSize,
    ) {}

    public function successful(): bool
    {
        return $this->errno === 0 && $this->httpStatus >= 200 && $this->httpStatus < 300;
    }
}
