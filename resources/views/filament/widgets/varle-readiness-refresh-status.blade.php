<x-filament-widgets::widget>
    <x-filament::section heading="Varle Readiness Refresh">
        @php($job = $this->getRefreshJob())
        @php($health = $this->getHealth())

        @if ($job === null)
            <p class="text-sm text-gray-500 dark:text-gray-400">No readiness refresh has been run yet.</p>
        @else
            <div class="mb-4">
                <x-filament::badge :color="$health['color'] ?? 'gray'">
                    {{ strtoupper($health['label'] ?? $job->status->value) }}
                </x-filament::badge>
            </div>

            @if (($health['health_status'] ?? null) === 'stuck')
                <div class="mb-4 rounded-lg border border-warning-300 bg-warning-50 px-3 py-2 text-sm text-warning-800 dark:border-warning-500/40 dark:bg-warning-500/10 dark:text-warning-200">
                    {{ $this->getSummaryMessage() }}
                </div>
            @elseif (($health['health_status'] ?? null) === 'failed')
                <div class="mb-4 rounded-lg border border-danger-300 bg-danger-50 px-3 py-2 text-sm text-danger-800 dark:border-danger-500/40 dark:bg-danger-500/10 dark:text-danger-200">
                    {{ $this->getSummaryMessage() }}
                </div>
            @elseif (($health['health_status'] ?? null) === 'completed')
                <div class="mb-4 rounded-lg border border-success-300 bg-success-50 px-3 py-2 text-sm text-success-800 dark:border-success-500/40 dark:bg-success-500/10 dark:text-success-200">
                    {{ $this->getSummaryMessage() }}
                </div>
            @else
                <p class="mb-4 text-sm text-gray-600 dark:text-gray-300">{{ $this->getSummaryMessage() }}</p>
            @endif

            <dl class="grid grid-cols-1 gap-3 text-sm">
                <div class="flex justify-between gap-4">
                    <dt class="text-gray-500 dark:text-gray-400">Progress</dt>
                    <dd class="font-medium">{{ $health['progress_label'] ?? '—' }}</dd>
                </div>
                <div class="flex justify-between gap-4">
                    <dt class="text-gray-500 dark:text-gray-400">Started</dt>
                    <dd>{{ $job->started_at?->format('Y-m-d H:i:s') ?? '—' }}</dd>
                </div>
                <div class="flex justify-between gap-4">
                    <dt class="text-gray-500 dark:text-gray-400">Finished</dt>
                    <dd>{{ $job->finished_at?->format('Y-m-d H:i:s') ?? '—' }}</dd>
                </div>
                <div class="flex justify-between gap-4">
                    <dt class="text-gray-500 dark:text-gray-400">Duration</dt>
                    <dd>{{ $this->getDuration() ?? '—' }}</dd>
                </div>
                <div class="flex justify-between gap-4">
                    <dt class="text-gray-500 dark:text-gray-400">Last heartbeat</dt>
                    <dd>{{ $job->heartbeat_at?->format('Y-m-d H:i:s') ?? '—' }}</dd>
                </div>
                <div class="flex justify-between gap-4">
                    <dt class="text-gray-500 dark:text-gray-400">Heartbeat age</dt>
                    <dd>{{ $this->getHeartbeatAge() }}</dd>
                </div>
                <div class="flex justify-between gap-4">
                    <dt class="text-gray-500 dark:text-gray-400">Current product</dt>
                    <dd class="text-right break-all">{{ $health['current_product_handle'] ?? '—' }}</dd>
                </div>
                <div class="flex justify-between gap-4">
                    <dt class="text-gray-500 dark:text-gray-400">Success count</dt>
                    <dd>{{ number_format($job->success_items) }}</dd>
                </div>
                <div class="flex justify-between gap-4">
                    <dt class="text-gray-500 dark:text-gray-400">Failed count</dt>
                    <dd class="{{ $job->failed_items > 0 ? 'text-danger-600 dark:text-danger-400 font-medium' : '' }}">
                        {{ number_format($job->failed_items) }}
                    </dd>
                </div>
                <div class="flex justify-between gap-4">
                    <dt class="text-gray-500 dark:text-gray-400">Error</dt>
                    <dd class="text-right break-all">{{ $job->error_message ?? '—' }}</dd>
                </div>
            </dl>
        @endif
    </x-filament::section>
</x-filament-widgets::widget>
