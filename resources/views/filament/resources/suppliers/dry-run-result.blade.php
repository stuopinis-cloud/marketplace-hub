@php
    $badgeClasses = [
        'danger' => 'bg-danger-100 text-danger-700',
        'warning' => 'bg-warning-100 text-warning-700',
        'success' => 'bg-success-100 text-success-700',
    ];
@endphp

<div class="space-y-4 text-sm">
    @if (! empty($error))
        <div class="rounded-lg border border-danger-300 bg-danger-50 p-3 text-danger-700">
            {{ $error }}
        </div>
    @endif

    @if (! empty($stats))
        <div class="grid grid-cols-2 gap-3 md:grid-cols-4">
            <div class="rounded border border-gray-200 p-2">
                <div class="text-xs text-gray-500">Parsed rows</div>
                <div class="text-lg font-semibold">{{ number_format($stats['parsed']) }}</div>
            </div>
            <div class="rounded border border-success-200 bg-success-50 p-2">
                <div class="text-xs text-success-700">Matched</div>
                <div class="text-lg font-semibold text-success-700">{{ number_format($stats['matched']) }}</div>
            </div>
            <div class="rounded border border-danger-200 bg-danger-50 p-2">
                <div class="text-xs text-danger-700">Unmatched</div>
                <div class="text-lg font-semibold text-danger-700">{{ number_format($stats['unmatched']) }}</div>
            </div>
            <div class="rounded border border-warning-200 bg-warning-50 p-2">
                <div class="text-xs text-warning-700">Ambiguous</div>
                <div class="text-lg font-semibold text-warning-700">{{ number_format($stats['ambiguous']) }}</div>
            </div>
            <div class="rounded border border-gray-200 p-2">
                <div class="text-xs text-gray-500">Missing SKU</div>
                <div class="text-lg font-semibold">{{ number_format($stats['missing_sku']) }}</div>
            </div>
            <div class="rounded border border-gray-200 p-2">
                <div class="text-xs text-gray-500">Missing quantity</div>
                <div class="text-lg font-semibold">{{ number_format($stats['missing_quantity']) }}</div>
            </div>
            <div class="rounded border border-gray-200 p-2">
                <div class="text-xs text-gray-500">Skipped rows</div>
                <div class="text-lg font-semibold">{{ number_format($stats['skipped']) }}</div>
            </div>
            <div class="rounded border border-gray-200 p-2">
                <div class="text-xs text-gray-500">Valid rows</div>
                <div class="text-lg font-semibold">{{ number_format($stats['valid_rows']) }}</div>
            </div>
        </div>

        <div class="rounded border border-gray-200 bg-gray-50 p-2 text-xs text-gray-600">
            This is a read-only simulation. No stock or matching changes were written to the database.
        </div>
    @endif

    @foreach ([
        ['title' => 'Sample unmatched rows', 'rows' => $unmatched_examples ?? [], 'color' => 'danger'],
        ['title' => 'Sample ambiguous rows', 'rows' => $ambiguous_examples ?? [], 'color' => 'warning'],
        ['title' => 'Sample matched rows', 'rows' => $matched_examples ?? [], 'color' => 'success'],
    ] as $group)
        @if (! empty($group['rows']))
            <div>
                <div class="font-medium text-gray-700">{{ $group['title'] }}</div>
                <div class="mt-2 max-h-64 overflow-auto rounded border border-gray-200">
                    <table class="min-w-full divide-y divide-gray-200 text-xs">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-2 py-1 text-left">SKU</th>
                                <th class="px-2 py-1 text-left">Stock</th>
                                <th class="px-2 py-1 text-left">Match status</th>
                                <th class="px-2 py-1 text-left">Match method</th>
                                <th class="px-2 py-1 text-left">Issue</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            @foreach ($group['rows'] as $row)
                                <tr>
                                    <td class="px-2 py-1 font-medium whitespace-nowrap">{{ $row['sku'] ?? '—' }}</td>
                                    <td class="px-2 py-1 whitespace-nowrap">{{ $row['stock_quantity'] ?? '—' }}</td>
                                    <td class="px-2 py-1 whitespace-nowrap">
                                        <span class="rounded px-1.5 py-0.5 text-xs {{ $badgeClasses[$group['color']] ?? '' }}">
                                            {{ $row['match_status'] ?? '—' }}
                                        </span>
                                    </td>
                                    <td class="px-2 py-1 whitespace-nowrap">{{ $row['match_method'] ?? '—' }}</td>
                                    <td class="px-2 py-1 whitespace-nowrap">{{ $row['issue_code'] ?? '—' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        @endif
    @endforeach

    @if (empty($error) && empty($stats))
        <div class="rounded-lg border border-gray-200 bg-gray-50 p-3 text-gray-600">
            No dry run data was returned.
        </div>
    @endif
</div>
