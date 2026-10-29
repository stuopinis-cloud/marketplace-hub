<x-filament-panels::page>
    @php
        $status = $this->previewStatus;
        $state = $status['status'] ?? 'idle';
        $stats = is_array($status['stats'] ?? null) ? $status['stats'] : [];
        $comparison = is_array($stats['comparison'] ?? null) ? $stats['comparison'] : [];
        $diagnostics = is_array($stats['diagnostics'] ?? null) ? $stats['diagnostics'] : [];
        $busy = in_array($state, ['pending', 'running'], true);

        $statusLabels = [
            'idle' => 'Nepaleista',
            'pending' => 'Laukiama',
            'running' => 'Generuojama',
            'ready' => 'Paruošta',
            'failed' => 'Klaida',
        ];

        $hoursSourceLabels = [
            'local' => 'Shopify vietinis',
            'supplier' => 'Tiekėjo likutis (Shopify kaina)',
            'supplier_only' => 'Tik tiekėjas',
            'backorder' => 'Backorder',
        ];
    @endphp

    <div @if ($busy) wire:poll.2s="refreshStatus" @endif class="space-y-6">
        <div class="rounded-xl bg-amber-50 p-4 text-sm text-amber-950 ring-1 ring-amber-200 dark:bg-amber-950/40 dark:text-amber-100 dark:ring-amber-900">
            Bendra izoliuota peržiūra — sugeneruoja <strong>pilną</strong> Pigu pasiūlymų XML taip, lyg šis tiekėjas būtų įjungtas,
            nekeičiant išsaugotos aktyvacijos ir neperrašant viešo <code>pigu-offers.xml</code>.
            Lyginama su dabartiniu viešu feed’u.
        </div>

        <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
            <div class="rounded-xl bg-white p-4 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
                <div class="text-sm text-gray-500">Būsena</div>
                <div class="mt-1 text-xl font-semibold">{{ $statusLabels[$state] ?? $state }}</div>
            </div>
            <div class="rounded-xl bg-white p-4 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
                <div class="text-sm text-gray-500">Apdorota produktų</div>
                <div class="mt-1 text-xl font-semibold">{{ $status['products_processed'] ?? 0 }}</div>
            </div>
            <div class="rounded-xl bg-white p-4 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
                <div class="text-sm text-gray-500">Sugeneruota pasiūlymų</div>
                <div class="mt-1 text-xl font-semibold">{{ $status['offers_generated'] ?? 0 }}</div>
            </div>
            <div class="rounded-xl bg-white p-4 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
                <div class="text-sm text-gray-500">Atmesta</div>
                <div class="mt-1 text-xl font-semibold">{{ $status['rejected'] ?? 0 }}</div>
            </div>
        </div>

        <div class="rounded-xl bg-white p-4 text-sm shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
            <div>Paskutinė sėkminga generacija:
                <strong>
                    @if (! empty($status['finished_at']) && $state === 'ready')
                        {{ \Illuminate\Support\Carbon::parse($status['finished_at'])->timezone('Europe/Vilnius')->format('Y-m-d H:i:s') }}
                    @else
                        —
                    @endif
                </strong>
            </div>
            <div class="mt-1">Trukmė:
                <strong>
                    @if (($status['duration_seconds'] ?? null) !== null && $state === 'ready')
                        {{ $status['duration_seconds'] }} s
                    @else
                        —
                    @endif
                </strong>
            </div>
            <div class="mt-1">Išsaugota Pigu aktyvacija:
                <strong>{{ ($status['pigu_enabled_saved'] ?? false) ? 'Aktyvuota' : 'Neaktyvuota' }}</strong>
            </div>
        </div>

        @if ($state === 'failed')
            <div class="rounded-xl bg-red-50 p-4 text-sm text-red-900 ring-1 ring-red-200 dark:bg-red-950/40 dark:text-red-100 dark:ring-red-900">
                <div class="font-semibold">Generavimas nepavyko</div>
                <div class="mt-1">{{ $status['error'] ?? 'Nežinoma klaida.' }}</div>
            </div>
        @endif

        @if ($state === 'ready' && $stats !== [])
            <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
                <div class="rounded-xl bg-white p-4 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
                    <div class="text-sm text-gray-500">Vietiniai (24h)</div>
                    <div class="mt-1 text-2xl font-semibold">{{ $stats['local_offers_count'] ?? 0 }}</div>
                </div>
                <div class="rounded-xl bg-white p-4 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
                    <div class="text-sm text-gray-500">Tiekėjo likutis</div>
                    <div class="mt-1 text-2xl font-semibold">{{ $stats['supplier_offers_count'] ?? 0 }}</div>
                </div>
                <div class="rounded-xl bg-white p-4 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
                    <div class="text-sm text-gray-500">Tik tiekėjas</div>
                    <div class="mt-1 text-2xl font-semibold">{{ $stats['supplier_only_offers'] ?? 0 }}</div>
                </div>
                <div class="rounded-xl bg-white p-4 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
                    <div class="text-sm text-gray-500">Backorder</div>
                    <div class="mt-1 text-2xl font-semibold">{{ $stats['backorder_offers_count'] ?? 0 }}</div>
                </div>
            </div>

            <div class="overflow-hidden rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
                <div class="border-b border-gray-200 px-4 py-3 font-semibold dark:border-white/10">
                    Palyginimas su viešu Pigu feed’u
                </div>
                <div class="grid gap-4 p-4 md:grid-cols-2 xl:grid-cols-3">
                    <div>
                        <div class="text-sm text-gray-500">Pridėti pasiūlymai</div>
                        <div class="text-xl font-semibold">{{ $comparison['offers_added'] ?? 0 }}</div>
                    </div>
                    <div>
                        <div class="text-sm text-gray-500">Pašalinti pasiūlymai</div>
                        <div class="text-xl font-semibold">{{ $comparison['offers_removed'] ?? 0 }}</div>
                    </div>
                    <div>
                        <div class="text-sm text-gray-500">Pakeistas likutis</div>
                        <div class="text-xl font-semibold">{{ $comparison['stock_changed'] ?? 0 }}</div>
                    </div>
                    <div>
                        <div class="text-sm text-gray-500">Pakeistas collectionhours</div>
                        <div class="text-xl font-semibold">{{ $comparison['collectionhours_changed'] ?? 0 }}</div>
                    </div>
                    <div>
                        <div class="text-sm text-gray-500">Pakeista kaina</div>
                        <div class="text-xl font-semibold">{{ $comparison['price_changed'] ?? 0 }}</div>
                    </div>
                    <div>
                        <div class="text-sm text-gray-500">Dubliuoti EAN / SKU</div>
                        <div class="text-xl font-semibold">
                            {{ ($comparison['duplicate_ean_conflicts'] ?? 0) }} / {{ ($comparison['duplicate_sku_conflicts'] ?? 0) }}
                        </div>
                    </div>
                </div>
            </div>

            <div class="overflow-hidden rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
                <div class="border-b border-gray-200 px-4 py-3 font-semibold dark:border-white/10">
                    Diagnostika (svarbūs SKU)
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="text-left text-gray-500">
                                <th class="px-4 py-2">SKU</th>
                                <th class="px-4 py-2">Likutis</th>
                                <th class="px-4 py-2">collectionhours</th>
                                <th class="px-4 py-2">Kaina</th>
                                <th class="px-4 py-2">Šaltinis</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($diagnostics as $sku => $row)
                                <tr class="border-t border-gray-100 dark:border-white/5">
                                    <td class="px-4 py-2 font-mono">{{ $row['sku'] ?? $sku }}</td>
                                    <td class="px-4 py-2">{{ $row['stock'] ?? '—' }}</td>
                                    <td class="px-4 py-2">{{ $row['collectionhours'] ?? '—' }}</td>
                                    <td class="px-4 py-2">{{ $row['price'] ?? '—' }}</td>
                                    <td class="px-4 py-2">{{ $hoursSourceLabels[$row['hours_source'] ?? ''] ?? ($row['hours_source'] ?? '—') }}</td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="5" class="px-4 py-3 text-gray-500">Diagnostinių SKU rezultatuose nėra.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        @elseif ($state === 'idle')
            <div class="rounded-xl bg-white p-4 text-sm text-gray-600 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:text-gray-300 dark:ring-white/10">
                Spauskite <strong>Generuoti bendrą XML peržiūrą</strong>, kad paleistumėte foninį generavimą.
            </div>
        @elseif ($busy)
            <div class="rounded-xl bg-white p-4 text-sm text-gray-600 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:text-gray-300 dark:ring-white/10">
                Generuojama fone… būsena atnaujinama automatiškai.
            </div>
        @endif
    </div>
</x-filament-panels::page>
