@php
    use App\Services\Marketplace\Varle\VarleIssueCodePresenter;

    $readinessLabel = ($analysis['is_ready_for_varle'] ?? false) ? 'Ready' : 'Not ready';
    $readinessColorClass = ($analysis['is_ready_for_varle'] ?? false) ? 'text-success-600' : 'text-danger-600';
    $issueCount = (int) ($analysis['issue_count'] ?? 0);

    $missingBarcodeVariants = collect($analysis['variant_diagnostics'] ?? [])
        ->filter(fn (array $variant): bool => blank($variant['barcode'] ?? null))
        ->values();

    $variantsWithoutImages = collect($analysis['variant_diagnostics'] ?? [])
        ->filter(fn (array $variant): bool => empty($variant['has_variant_image']))
        ->values();

    $categoryExplanation = $analysis['category_explanation'] ?? [];
    $deliveryRule = $analysis['delivery_rule'] ?? [];
@endphp

<div class="space-y-6 text-sm">
    <div class="grid gap-3 md:grid-cols-2">
        <div>
            <div class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">Product</div>
            <div class="font-medium">{{ $record->title }}</div>
            <div class="text-gray-600 dark:text-gray-300">{{ $record->handle }}</div>
        </div>
        <div>
            <div class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">Vendor</div>
            <div>{{ $record->vendor ?: '—' }}</div>
        </div>
        <div>
            <div class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">Readiness</div>
            <div class="{{ $readinessColorClass }} font-medium">{{ $readinessLabel }}</div>
        </div>
        <div>
            <div class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">Issue count</div>
            <div class="font-medium">{{ $issueCount }}</div>
        </div>
    </div>

    <div>
        <div class="mb-2 text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">Issue codes</div>
        <div class="flex flex-wrap gap-1">
            @forelse (VarleIssueCodePresenter::badges($analysis['issue_codes'] ?? []) as $badge)
                <span class="inline-flex rounded-md bg-gray-100 px-2 py-0.5 text-xs font-medium text-gray-800 dark:bg-gray-800 dark:text-gray-200">{{ $badge['label'] }}</span>
            @empty
                <span class="text-gray-500 dark:text-gray-400">No issues recorded.</span>
            @endforelse
        </div>
    </div>

    <div class="rounded-lg border border-gray-200 p-4 dark:border-gray-700">
        <h3 class="mb-3 font-semibold">Barcode</h3>
        <dl class="grid gap-2 md:grid-cols-2">
            <div>
                <dt class="text-gray-500 dark:text-gray-400">Status</dt>
                <dd>{{ str($analysis['barcode_status'] ?? '—')->replace('_', ' ')->title() }}</dd>
            </div>
            <div class="md:col-span-2">
                <dt class="text-gray-500 dark:text-gray-400">Missing variant SKUs</dt>
                <dd>
                    @if ($missingBarcodeVariants->isEmpty())
                        —
                    @else
                        {{ $missingBarcodeVariants->pluck('sku')->filter()->implode(', ') ?: '—' }}
                    @endif
                </dd>
            </div>
        </dl>
    </div>

    <div class="rounded-lg border border-gray-200 p-4 dark:border-gray-700">
        <h3 class="mb-3 font-semibold">Images</h3>
        <dl class="grid gap-2 md:grid-cols-2">
            <div>
                <dt class="text-gray-500 dark:text-gray-400">Status</dt>
                <dd>{{ str($analysis['image_status'] ?? '—')->replace('_', ' ')->title() }}</dd>
            </div>
            <div class="md:col-span-2">
                <dt class="text-gray-500 dark:text-gray-400">Variants without images</dt>
                <dd>
                    @if ($variantsWithoutImages->isEmpty())
                        —
                    @else
                        {{ $variantsWithoutImages->pluck('sku')->filter()->implode(', ') ?: '—' }}
                    @endif
                </dd>
            </div>
        </dl>
    </div>

    <div class="rounded-lg border border-gray-200 p-4 dark:border-gray-700">
        <h3 class="mb-3 font-semibold">Category</h3>
        <dl class="grid gap-2 md:grid-cols-2">
            <div>
                <dt class="text-gray-500 dark:text-gray-400">Mapped category</dt>
                <dd>{{ $analysis['mapped_category_preview'] ?? '—' }}</dd>
            </div>
            <div>
                <dt class="text-gray-500 dark:text-gray-400">Fallback used</dt>
                <dd>{{ ! empty($categoryExplanation['fallback_used']) ? 'Yes' : 'No' }}</dd>
            </div>
            <div>
                <dt class="text-gray-500 dark:text-gray-400">Status</dt>
                <dd>{{ str($analysis['category_status'] ?? '—')->replace('_', ' ')->title() }}</dd>
            </div>
        </dl>
    </div>

    <div class="rounded-lg border border-gray-200 p-4 dark:border-gray-700">
        <h3 class="mb-3 font-semibold">Stock</h3>
        <dl class="grid gap-2">
            <div>
                <dt class="text-gray-500 dark:text-gray-400">Status</dt>
                <dd>{{ str($analysis['stock_status'] ?? '—')->replace('_', ' ')->title() }}</dd>
            </div>
        </dl>
        <div class="mt-3 overflow-x-auto">
            <table class="min-w-full text-left text-xs">
                <thead class="text-gray-500 dark:text-gray-400">
                    <tr>
                        <th class="py-1 pr-3">SKU</th>
                        <th class="py-1 pr-3">Local</th>
                        <th class="py-1 pr-3">Supplier</th>
                        <th class="py-1 pr-3">Source</th>
                        <th class="py-1 pr-3">Stale</th>
                        <th class="py-1 pr-3">Reason</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach (($analysis['variant_diagnostics'] ?? []) as $variant)
                        <tr class="border-t border-gray-100 dark:border-gray-800">
                            <td class="py-1 pr-3 font-mono">{{ $variant['sku'] ?? '—' }}</td>
                            <td class="py-1 pr-3">{{ $variant['local_quantity'] ?? 0 }}</td>
                            <td class="py-1 pr-3">{{ $variant['supplier_quantity'] ?? 0 }}</td>
                            <td class="py-1 pr-3">{{ $variant['availability_source'] ?? '—' }}</td>
                            <td class="py-1 pr-3">{{ ! empty($variant['supplier_stock_stale']) ? 'Yes' : 'No' }}</td>
                            <td class="py-1 pr-3">{{ $variant['skipped_reason'] ?? ($variant['issue_code'] ?? '—') }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>

    <div class="rounded-lg border border-gray-200 p-4 dark:border-gray-700">
        <h3 class="mb-3 font-semibold">Delivery</h3>
        <dl class="grid gap-2 md:grid-cols-2">
            <div>
                <dt class="text-gray-500 dark:text-gray-400">Resolved delivery text</dt>
                <dd>{{ $analysis['delivery_text_preview'] ?? '—' }}</dd>
            </div>
            <div>
                <dt class="text-gray-500 dark:text-gray-400">Rule source</dt>
                <dd>{{ $deliveryRule['source'] ?? ($deliveryRule['status'] ?? '—') }}</dd>
            </div>
            <div>
                <dt class="text-gray-500 dark:text-gray-400">Rule status</dt>
                <dd>{{ str($analysis['vendor_delivery_rule_status'] ?? '—')->replace('_', ' ')->title() }}</dd>
            </div>
        </dl>
    </div>

    <div class="rounded-lg border border-gray-200 p-4 dark:border-gray-700">
        <h3 class="mb-3 font-semibold">Variants</h3>
        <dl class="grid gap-2 md:grid-cols-3">
            <div>
                <dt class="text-gray-500 dark:text-gray-400">Total</dt>
                <dd>{{ $record->variants_count ?? 0 }}</dd>
            </div>
            <div>
                <dt class="text-gray-500 dark:text-gray-400">Exportable</dt>
                <dd>{{ (int) ($analysis['exportable_variants_count'] ?? 0) }}</dd>
            </div>
            <div>
                <dt class="text-gray-500 dark:text-gray-400">Skipped</dt>
                <dd>{{ (int) ($analysis['skipped_variants_count'] ?? 0) }}</dd>
            </div>
        </dl>
    </div>
</div>
