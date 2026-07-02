<?php

namespace App\Services\Automation;

class AutomationScheduleRunResult
{
    public function __construct(
        public readonly bool $ran,
        public readonly string $status,
        public readonly ?string $message = null,
    ) {}

    public static function blocked(string $message): self
    {
        return new self(false, 'blocked', $message);
    }

    public static function skipped(string $message): self
    {
        return new self(false, 'skipped', $message);
    }

    public static function success(?string $message = null): self
    {
        return new self(true, 'success', $message);
    }

    public static function failed(string $message): self
    {
        return new self(true, 'failed', $message);
    }
}
