<?php

namespace App\Services\Sync;

use App\Models\SyncJob;

class JobDispatchResult
{
    public function __construct(
        public readonly bool $dispatched,
        public readonly bool $alreadyRunning = false,
        public readonly ?SyncJob $syncJob = null,
        public readonly ?string $message = null,
    ) {}

    public static function dispatched(?SyncJob $syncJob = null, ?string $message = null): self
    {
        return new self(
            dispatched: true,
            syncJob: $syncJob,
            message: $message ?? 'Job queued.',
        );
    }

    public static function alreadyRunning(?SyncJob $syncJob = null, ?string $message = null): self
    {
        return new self(
            dispatched: false,
            alreadyRunning: true,
            syncJob: $syncJob,
            message: $message ?? 'Job already running.',
        );
    }
}
