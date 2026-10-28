<x-filament-panels::page>
    @php
        $overview = $this->getOverview();
        $rows = $this->getSupplierRows();
    @endphp

    <div class="space-y-6">
        <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
            <div class="rounded-xl bg-white p-4 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
                <div class="text-sm text-gray-500">Paskutinis Shopify importas</div>
                <div class="mt-1 text-lg font-semibold">{{ $this->formatTimestamp($overview['last_shopify_import_at'] ?? null) }}</div>
            </div>
            <div class="rounded-xl bg-white p-4 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
                <div class="text-sm text-gray-500">Paskutinė tiekėjų sinchronizacija</div>
                <div class="mt-1 text-lg font-semibold">{{ $this->formatTimestamp($overview['last_supplier_sync_at'] ?? null) }}</div>
            </div>
            <div class="rounded-xl bg-white p-4 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
                <div class="text-sm text-gray-500">Paskutinė pasiūlymų generacija</div>
                <div class="mt-1 text-lg font-semibold">{{ $this->formatTimestamp($overview['offers_generated_at'] ?? null) }}</div>
            </div>
            <div class="rounded-xl bg-white p-4 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
                <div class="text-sm text-gray-500">Numatomi papildomi tiekėjo pasiūlymai</div>
                <div class="mt-1 text-lg font-semibold">{{ $overview['estimated_supplier_only_offers'] ?? 0 }}</div>
            </div>
        </div>

        <div class="rounded-xl bg-white p-4 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
            <div class="mb-2 text-sm text-gray-500">Viešas feed</div>
            <a href="{{ $overview['offers_public_url'] ?? 'https://hub.gudle.lt/feeds/pigu-offers.xml' }}"
               target="_blank"
               class="text-primary-600 underline">
                {{ $overview['offers_public_url'] ?? 'https://hub.gudle.lt/feeds/pigu-offers.xml' }}
            </a>
            <div class="mt-1 text-sm text-gray-500">
                Būsena: {{ $overview['feed_status'] ?? '—' }} ·
                Aktyvių Pigu XML tiekėjų: {{ $overview['pigu_supplier_count'] ?? 0 }}
            </div>
            <p class="mt-3 text-sm text-gray-600 dark:text-gray-300">
                Eksportuoti pasiūlymai nereiškia, kad Pigu.lt juos automatiškai priėmė.
                Tiekėjo produktai be pilno katalogo laukiami marketplace sutapimo.
            </p>
        </div>

        <div class="overflow-hidden rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
            <div class="border-b border-gray-200 px-4 py-3 font-semibold dark:border-white/10">
                Tiekėjų sąrašas
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="text-left text-gray-500">
                            <th class="px-4 py-2">Tiekėjas</th>
                            <th class="px-4 py-2">Produktai</th>
                            <th class="px-4 py-2">Galiojantys pasiūlymai</th>
                            <th class="px-4 py-2">Paskutinė sinchronizacija</th>
                            <th class="px-4 py-2">Būsena</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($rows as $row)
                            <tr class="border-t border-gray-100 dark:border-white/5">
                                <td class="px-4 py-2 font-medium">{{ $row['supplier']->name }}</td>
                                <td class="px-4 py-2">{{ $row['products'] }}</td>
                                <td class="px-4 py-2">{{ $row['valid_offers'] }}</td>
                                <td class="px-4 py-2">{{ $row['last_sync'] ?? '—' }}</td>
                                <td class="px-4 py-2">{{ $row['status'] }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5" class="px-4 py-6 text-gray-500">
                                    Dar nėra XML tiekėjų. Pridėkite juos skiltyje „Tiekėjų XML“.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</x-filament-panels::page>
