<x-filament-widgets::widget>
    @php($snapshot = $this->getSnapshot())
    <x-filament::section>
        <x-slot name="heading">Supplier onboarding health</x-slot>
        <x-slot name="description">Sync readiness and matching quality across all suppliers.</x-slot>

        <div class="grid gap-4 md:grid-cols-3 lg:grid-cols-6">
            <div>
                <div class="text-sm text-gray-500 dark:text-gray-400">Suppliers</div>
                <div class="text-lg font-semibold">{{ $snapshot['total'] }}</div>
            </div>
            <div>
                <div class="text-sm text-gray-500 dark:text-gray-400">Sync ready & enabled</div>
                <div class="text-lg font-semibold">{{ $snapshot['enabled'] }}</div>
            </div>
            <div>
                <div class="text-sm text-gray-500 dark:text-gray-400">Due now</div>
                <div class="text-lg font-semibold">{{ $snapshot['due_now'] }}</div>
            </div>
            <div>
                <div class="text-sm text-gray-500 dark:text-gray-400">Failed last sync</div>
                <div class="text-lg font-semibold {{ $snapshot['failed_last_sync'] > 0 ? 'text-danger-600' : '' }}">
                    {{ $snapshot['failed_last_sync'] }}
                </div>
            </div>
            <div>
                <div class="text-sm text-gray-500 dark:text-gray-400">Unmatched products</div>
                <div class="text-lg font-semibold {{ $snapshot['unmatched_total'] > 0 ? 'text-warning-600' : '' }}">
                    {{ $snapshot['unmatched_total'] }}
                </div>
            </div>
            <div>
                <div class="text-sm text-gray-500 dark:text-gray-400">Ambiguous products</div>
                <div class="text-lg font-semibold {{ $snapshot['ambiguous_total'] > 0 ? 'text-warning-600' : '' }}">
                    {{ $snapshot['ambiguous_total'] }}
                </div>
            </div>
        </div>
    </x-filament::section>
</x-filament-widgets::widget>
