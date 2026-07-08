<div class="fi-section rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
    <div class="fi-section-content p-6">
        <h3 class="text-base font-semibold text-gray-950 dark:text-white">Readiness breakdowns</h3>
        <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">Counts use cached readiness columns. Run <code class="text-xs">varle:refresh-readiness</code> after imports.</p>

        <div class="mt-6 grid gap-6 lg:grid-cols-2 xl:grid-cols-3">
            @foreach ([
                'By vendor' => $byVendor,
                'By product type' => $byProductType,
                'By barcode status' => $byBarcodeStatus,
                'By stock status' => $byStockStatus,
                'By category status' => $byCategoryStatus,
            ] as $title => $rows)
                <div>
                    <h4 class="text-sm font-medium text-gray-950 dark:text-white">{{ $title }}</h4>
                    <ul class="mt-2 space-y-1 text-sm">
                        @forelse ($rows as $row)
                            <li class="flex justify-between gap-4 text-gray-700 dark:text-gray-300">
                                <span class="truncate">{{ $row->label }}</span>
                                <span class="font-medium">{{ $row->count }}</span>
                            </li>
                        @empty
                            <li class="text-gray-500">No cached data yet.</li>
                        @endforelse
                    </ul>
                </div>
            @endforeach

            <div>
                <h4 class="text-sm font-medium text-gray-950 dark:text-white">By issue code</h4>
                <ul class="mt-2 space-y-1 text-sm">
                    @forelse (collect($byIssueCode)->filter(fn ($count) => $count > 0) as $code => $count)
                        <li class="flex justify-between gap-4 text-gray-700 dark:text-gray-300">
                            <span class="truncate">{{ $code }}</span>
                            <span class="font-medium">{{ $count }}</span>
                        </li>
                    @empty
                        <li class="text-gray-500">No issues in cache.</li>
                    @endforelse
                </ul>
            </div>
        </div>
    </div>
</div>
