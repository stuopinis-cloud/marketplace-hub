<?php

namespace App\Services\Marketplace\Varle;

use App\Models\SyncJob;

class VarleReadinessRefreshDispatchResult
{
    public function __construct(
        public readonly bool $dispatched,
        public readonly bool $alreadyRunning,
        public readonly ?SyncJob $syncJob = null,
        public readonly ?string $message = null,
    ) {}

    public static function dispatched(SyncJob $syncJob): self
    {
        return new self(dispatched: true, alreadyRunning: false, syncJob: $syncJob);
    }

    public static function alreadyRunning(?string $message = null): self
    {
        return new self(
            dispatched: false,
            alreadyRunning: true,
            message: $message ?? 'A Varle readiness refresh is already running.',
        );
    }
}
