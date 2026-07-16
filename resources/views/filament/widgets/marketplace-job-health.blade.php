<x-filament-widgets::widget>
    @php($snapshot = $this->getSnapshot())
    <x-filament::section>
        <x-slot name="heading">Job health</x-slot>
        <x-slot name="description">Queue worker and SyncJob status. Long-running work must run via queue:work.</x-slot>

        <div class="grid gap-4 md:grid-cols-4">
            <div>
                <div class="text-sm text-gray-500 dark:text-gray-400">Queue connection</div>
                <div class="text-lg font-semibold">{{ $snapshot['queue_connection'] }}</div>
            </div>
            <div>
                <div class="text-sm text-gray-500 dark:text-gray-400">Pending queue jobs</div>
                <div class="text-lg font-semibold">{{ $snapshot['pending_jobs'] ?? '—' }}</div>
            </div>
            <div>
                <div class="text-sm text-gray-500 dark:text-gray-400">Failed queue jobs</div>
                <div class="text-lg font-semibold">{{ $snapshot['failed_jobs'] ?? '—' }}</div>
            </div>
            <div>
                <div class="text-sm text-gray-500 dark:text-gray-400">Running / stale SyncJobs</div>
                <div class="text-lg font-semibold">{{ $snapshot['running_jobs'] }} / {{ $snapshot['stale_jobs'] }}</div>
            </div>
        </div>

        <div class="mt-6 grid gap-4 md:grid-cols-3">
            <div>
                <div class="text-sm text-gray-500 dark:text-gray-400">Latest Shopify import</div>
                <div class="font-medium">{{ $this->getShopifyLabel() }}</div>
            </div>
            <div>
                <div class="text-sm text-gray-500 dark:text-gray-400">Latest Varle export</div>
                <div class="font-medium">{{ $this->getVarleLabel() }}</div>
            </div>
            <div>
                <div class="text-sm text-gray-500 dark:text-gray-400">Latest daily sync</div>
                <div class="font-medium">{{ $this->getDailyLabel() }}</div>
            </div>
        </div>

        <p class="mt-6 text-sm text-gray-600 dark:text-gray-300">
            Worker command:
            <code class="rounded bg-gray-100 px-1 py-0.5 dark:bg-gray-800">{{ $snapshot['worker_hint'] }}</code>
        </p>
    </x-filament::section>
</x-filament-widgets::widget>
