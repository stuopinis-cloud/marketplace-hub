<x-filament-panels::page>
    @php
        /** @var array<string, mixed>|null $preview */
        $preview = $this->preview;
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

    @if ($preview)
        <div class="space-y-6">
            <div class="rounded-xl bg-amber-50 p-4 text-sm text-amber-950 ring-1 ring-amber-200 dark:bg-amber-950/40 dark:text-amber-100 dark:ring-amber-900">
                Izoliuota peržiūra — negeneruoja viešo <code>pigu-offers.xml</code>, neatnaujina FeedFile įrašų
                ir nekeičia tiekėjo aktyvacijos
                (išsaugota būsena: {{ ($preview['pigu_enabled_saved'] ?? false) ? 'Aktyvuota' : 'Neaktyvuota' }}).
                XML rodo tik šio tiekėjo indėlį į kombinuotą feed’ą, gerbiant kitų tiekėjų prioritetą.
            </div>

            <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                <div class="rounded-xl bg-white p-4 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
                    <div class="text-sm text-gray-500">Iš viso tiekėjo produktų</div>
                    <div class="mt-1 text-2xl font-semibold">{{ $preview['total_supplier_products'] ?? 0 }}</div>
                </div>
                <div class="rounded-xl bg-white p-4 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
                    <div class="text-sm text-gray-500">Galiojantys pasiūlymai</div>
                    <div class="mt-1 text-2xl font-semibold">{{ $preview['valid_offers'] ?? 0 }}</div>
                </div>
                <div class="rounded-xl bg-white p-4 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
                    <div class="text-sm text-gray-500">Shopify sutapimai</div>
                    <div class="mt-1 text-2xl font-semibold">{{ $preview['shopify_matched_offers'] ?? 0 }}</div>
                </div>
                <div class="rounded-xl bg-white p-4 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
                    <div class="text-sm text-gray-500">Tik tiekėjo pasiūlymai</div>
                    <div class="mt-1 text-2xl font-semibold">{{ $preview['supplier_only_offers'] ?? 0 }}</div>
                </div>
                <div class="rounded-xl bg-white p-4 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
                    <div class="text-sm text-gray-500">Deduplikuota</div>
                    <div class="mt-1 text-2xl font-semibold">{{ $preview['deduplicated_offers'] ?? 0 }}</div>
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
                            @forelse (($preview['skipped_by_reason'] ?? []) as $code => $count)
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
                            @forelse (($preview['examples'] ?? []) as $example)
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
        </div>
    @endif
</x-filament-panels::page>
