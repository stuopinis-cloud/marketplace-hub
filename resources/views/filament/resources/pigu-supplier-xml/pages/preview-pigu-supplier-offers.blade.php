<x-filament-panels::page>
    @php
        $status = $this->previewStatus;
        $state = $status['status'] ?? 'idle';
        $stats = is_array($status['stats'] ?? null) ? $status['stats'] : [];
        $busy = in_array($state, ['pending', 'running'], true);

        $statusLabels = [
            'idle' => 'Nepaleista',
            'pending' => 'Laukiama',
            'running' => 'Generuojama',
            'ready' => 'Paruošta',
            'failed' => 'Klaida',
        ];

        $hoursSourceLabels = [
            'supplier' => 'Shopify (tiekėjo likutis)',
            'supplier_only' => 'Tik tiekėjas',
            'local' => 'Vietinis',
            'backorder' => 'Backorder',
        ];
        $skipLabels = [
            'missing_sku' => 'Trūksta SKU',
            'missing_ean' => 'Trūksta EAN',
            'invalid_ean' => 'Netinkamas EAN',
            'invalid_price' => 'Netinkama kaina',
            'missing_price' => 'Trūksta kainos',
            'no_exportable_stock' => 'Nėra eksportuojamo likučio',
            'duplicate_ean' => 'Dubliuotas EAN',
            'duplicate_sku' => 'Dubliuotas SKU',
            'stale_supplier_stock' => 'Pasenęs likutis',
            'not_exportable' => 'Neeksportuojama',
            'excluded_from_pigu' => 'Išimta iš Pigu',
        ];
    @endphp

    <div @if ($busy) wire:poll.2s="refreshStatus" @endif class="space-y-6">
        <div class="rounded-xl bg-amber-50 p-4 text-sm text-amber-950 ring-1 ring-amber-200 dark:bg-amber-950/40 dark:text-amber-100 dark:ring-amber-900">
            Izoliuota peržiūra — negeneruoja viešo <code>pigu-offers.xml</code>, neatnaujina FeedFile įrašų
            ir nekeičia tiekėjo aktyvacijos
            (išsaugota būsena: {{ ($status['pigu_enabled_saved'] ?? false) ? 'Aktyvuota' : 'Neaktyvuota' }}).
            Generavimas vyksta fone; puslapis neužkrauna viso katalogo HTTP užklausoje.
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
            <div class="mt-1">Tiekėjo produktų (DB):
                <strong>{{ $status['total_supplier_products'] ?? 0 }}</strong>
            </div>
        </div>

        @if ($state === 'failed')
            <div class="rounded-xl bg-red-50 p-4 text-sm text-red-900 ring-1 ring-red-200 dark:bg-red-950/40 dark:text-red-100 dark:ring-red-900">
                <div class="font-semibold">Generavimas nepavyko</div>
                <div class="mt-1">{{ $status['error'] ?? 'Nežinoma klaida.' }}</div>
            </div>
        @endif

        @if ($state === 'ready' && $stats !== [])
            <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                <div class="rounded-xl bg-white p-4 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
                    <div class="text-sm text-gray-500">Galiojantys pasiūlymai</div>
                    <div class="mt-1 text-2xl font-semibold">{{ $stats['valid_offers'] ?? 0 }}</div>
                </div>
                <div class="rounded-xl bg-white p-4 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
                    <div class="text-sm text-gray-500">Shopify sutapimai</div>
                    <div class="mt-1 text-2xl font-semibold">{{ $stats['shopify_matched_offers'] ?? 0 }}</div>
                </div>
                <div class="rounded-xl bg-white p-4 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
                    <div class="text-sm text-gray-500">Tik tiekėjo pasiūlymai</div>
                    <div class="mt-1 text-2xl font-semibold">{{ $stats['supplier_only_offers'] ?? 0 }}</div>
                </div>
                <div class="rounded-xl bg-white p-4 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
                    <div class="text-sm text-gray-500">Deduplikuota</div>
                    <div class="mt-1 text-2xl font-semibold">{{ $stats['deduplicated_offers'] ?? 0 }}</div>
                </div>
            </div>

            <div class="overflow-hidden rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
                <div class="border-b border-gray-200 px-4 py-3 font-semibold dark:border-white/10">
                    Atmesta pagal priežastį
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="text-left text-gray-500">
                                <th class="px-4 py-2">Priežastis</th>
                                <th class="px-4 py-2">Kiekis</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse (($stats['skipped_by_reason'] ?? []) as $code => $count)
                                <tr class="border-t border-gray-100 dark:border-white/5">
                                    <td class="px-4 py-2">{{ $skipLabels[$code] ?? $code }}</td>
                                    <td class="px-4 py-2">{{ $count }}</td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="2" class="px-4 py-3 text-gray-500">Atmestų pasiūlymų nėra.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="overflow-hidden rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
                <div class="border-b border-gray-200 px-4 py-3 font-semibold dark:border-white/10">
                    Pavyzdžiai (SKU, EAN, kaina, likutis, collectionhours)
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="text-left text-gray-500">
                                <th class="px-4 py-2">SKU</th>
                                <th class="px-4 py-2">EAN</th>
                                <th class="px-4 py-2">Kaina</th>
                                <th class="px-4 py-2">Likutis</th>
                                <th class="px-4 py-2">collectionhours</th>
                                <th class="px-4 py-2">Šaltinis</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse (($stats['examples'] ?? []) as $example)
                                <tr class="border-t border-gray-100 dark:border-white/5">
                                    <td class="px-4 py-2 font-mono">{{ $example['sku'] ?? '—' }}</td>
                                    <td class="px-4 py-2 font-mono">{{ $example['ean'] ?? '—' }}</td>
                                    <td class="px-4 py-2">{{ $example['price'] ?? '—' }}</td>
                                    <td class="px-4 py-2">{{ $example['stock'] ?? '—' }}</td>
                                    <td class="px-4 py-2">{{ $example['collectionhours'] ?? '—' }}</td>
                                    <td class="px-4 py-2">{{ $hoursSourceLabels[$example['hours_source'] ?? ''] ?? ($example['hours_source'] ?? '—') }}</td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="6" class="px-4 py-3 text-gray-500">Šis tiekėjas šiuo metu neprisideda jokio pasiūlymo.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        @elseif ($state === 'idle')
            <div class="rounded-xl bg-white p-4 text-sm text-gray-600 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:text-gray-300 dark:ring-white/10">
                Spauskite <strong>Generuoti XML peržiūrą</strong>, kad paleistumėte foninį generavimą.
            </div>
        @elseif ($busy)
            <div class="rounded-xl bg-white p-4 text-sm text-gray-600 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:text-gray-300 dark:ring-white/10">
                Generuojama fone… būsena atnaujinama automatiškai.
            </div>
        @endif
    </div>
</x-filament-panels::page>
