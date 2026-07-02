<x-filament-widgets::widget>
    @php
        $export = $this->getExport();
        $metrics = $this->getMetrics();
    @endphp

    <x-filament::section heading="Latest Varle export">
        @if ($export === null)
            <p class="text-sm text-gray-500 dark:text-gray-400">No Varle export has been run yet.</p>
        @else
            <dl class="grid grid-cols-1 gap-3 text-sm">
                <div class="flex justify-between gap-4">
                    <dt class="text-gray-500 dark:text-gray-400">Status</dt>
                    <dd class="font-medium">{{ $export->status?->label() ?? '—' }}</dd>
                </div>
                <div class="flex justify-between gap-4">
                    <dt class="text-gray-500 dark:text-gray-400">Started</dt>
                    <dd>{{ $export->started_at?->format('Y-m-d H:i:s') ?? '—' }}</dd>
                </div>
                <div class="flex justify-between gap-4">
                    <dt class="text-gray-500 dark:text-gray-400">Finished</dt>
                    <dd>{{ $export->finished_at?->format('Y-m-d H:i:s') ?? '—' }}</dd>
                </div>
                <div class="flex justify-between gap-4">
                    <dt class="text-gray-500 dark:text-gray-400">Exported variants</dt>
                    <dd>{{ number_format($metrics->exportedVariantsCount($export)) }}</dd>
                </div>
                <div class="flex justify-between gap-4">
                    <dt class="text-gray-500 dark:text-gray-400">Exported products</dt>
                    <dd>{{ number_format($metrics->exportedProductsCount($export)) }}</dd>
                </div>
                <div class="flex justify-between gap-4">
                    <dt class="text-gray-500 dark:text-gray-400">Skipped</dt>
                    <dd class="{{ $metrics->skippedVariantsCount($export) > 0 ? 'text-danger-600 dark:text-danger-400 font-medium' : '' }}">
                        {{ number_format($metrics->skippedVariantsCount($export)) }}
                    </dd>
                </div>
                <div class="flex flex-col gap-1">
                    <dt class="text-gray-500 dark:text-gray-400">Public XML URL</dt>
                    <dd>
                        <a
                            href="{{ $metrics->publicFeedUrl($export) }}"
                            target="_blank"
                            rel="noopener noreferrer"
                            class="break-all text-primary-600 hover:underline dark:text-primary-400"
                        >
                            {{ $metrics->publicFeedUrl($export) }}
                        </a>
                    </dd>
                </div>
                @if ($metrics->failedCsvUrl($export))
                    <div class="flex flex-col gap-1">
                        <dt class="text-gray-500 dark:text-gray-400">Failed CSV</dt>
                        <dd>
                            <a
                                href="{{ $metrics->failedCsvUrl($export) }}"
                                class="text-primary-600 hover:underline dark:text-primary-400"
                            >
                                Download varle_failed_{{ $export->id }}.csv
                            </a>
                        </dd>
                    </div>
                @endif
            </dl>
        @endif
    </x-filament::section>
</x-filament-widgets::widget>
