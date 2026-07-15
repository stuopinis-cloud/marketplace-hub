<x-filament-panels::page>
    <div class="space-y-6">
        <x-filament::section>
            <x-slot name="heading">Bulk category selection</x-slot>
            <p class="text-sm text-gray-600 dark:text-gray-300">
                Select Shopify collections/categories in the table below, then use the header actions to include or exclude their products from Varle export.
                Overlapping categories are handled safely using distinct product IDs.
            </p>
        </x-filament::section>

        {{ $this->table }}
    </div>
</x-filament-panels::page>
