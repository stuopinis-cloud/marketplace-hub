<x-filament-widgets::widget>
    <x-filament::section heading="Latest Shopify import">
        @php($import = $this->getImport())

        @if ($import === null)
            <p class="text-sm text-gray-500 dark:text-gray-400">No Shopify import has been run yet.</p>
        @else
            <dl class="grid grid-cols-1 gap-3 text-sm">
                <div class="flex justify-between gap-4">
                    <dt class="text-gray-500 dark:text-gray-400">Status</dt>
                    <dd class="font-medium">{{ $import->status?->label() ?? '—' }}</dd>
                </div>
                <div class="flex justify-between gap-4">
                    <dt class="text-gray-500 dark:text-gray-400">Started</dt>
                    <dd>{{ $import->started_at?->format('Y-m-d H:i:s') ?? '—' }}</dd>
                </div>
                <div class="flex justify-between gap-4">
                    <dt class="text-gray-500 dark:text-gray-400">Finished</dt>
                    <dd>{{ $import->finished_at?->format('Y-m-d H:i:s') ?? '—' }}</dd>
                </div>
                <div class="flex justify-between gap-4">
                    <dt class="text-gray-500 dark:text-gray-400">Success items</dt>
                    <dd>{{ number_format($import->success_items) }}</dd>
                </div>
                <div class="flex justify-between gap-4">
                    <dt class="text-gray-500 dark:text-gray-400">Failed items</dt>
                    <dd class="{{ $import->failed_items > 0 ? 'text-danger-600 dark:text-danger-400 font-medium' : '' }}">
                        {{ number_format($import->failed_items) }}
                    </dd>
                </div>
            </dl>
        @endif
    </x-filament::section>
</x-filament-widgets::widget>
