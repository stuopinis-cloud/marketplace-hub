@php
    $rowsToRender = null;
    $rowsHeading = null;

    if (($type ?? null) === 'csv' && ! empty($preview_rows ?? [])) {
        $rowsToRender = $preview_rows;
        $rowsHeading = 'First '.count($preview_rows).' rows';
    } elseif (($type ?? null) === 'xml' && ! empty($sample_entries ?? [])) {
        $rowsToRender = $sample_entries;
        $rowsHeading = 'Sample entries';
    } elseif (($type ?? null) === 'json' && ! empty($sample_rows ?? [])) {
        $rowsToRender = $sample_rows;
        $rowsHeading = 'Sample rows';
    }

    $flatten = function (array $rows): array {
        $keys = [];
        foreach ($rows as $row) {
            if (is_array($row)) {
                $keys = array_unique(array_merge($keys, array_keys($row)));
            }
        }

        return [
            'keys' => $keys,
            'rows' => array_map(function ($row) use ($keys) {
                return array_map(function ($key) use ($row) {
                    $value = is_array($row) ? ($row[$key] ?? null) : null;

                    if (is_array($value)) {
                        return json_encode($value);
                    }

                    if (is_bool($value)) {
                        return $value ? 'true' : 'false';
                    }

                    return $value;
                }, $keys);
            }, $rows),
        ];
    };
@endphp

<div class="space-y-4 text-sm">
    <div class="flex items-center gap-2">
        <span class="rounded bg-gray-100 px-2 py-1 text-xs font-medium uppercase text-gray-600">{{ $type ?? 'unknown' }}</span>
        @if (! empty($entry_count) || ! empty($item_count))
            <span class="text-xs text-gray-500">
                {{ $entry_count ?? $item_count }} entries
                @if (! empty($skipped_count))
                    &middot; {{ $skipped_count }} skipped
                @endif
            </span>
        @endif
        @if (! empty($detected_delimiter))
            <span class="text-xs text-gray-500">Delimiter: {{ $detected_delimiter }}</span>
        @endif
    </div>

    @if (! empty($error))
        <div class="rounded-lg border border-danger-300 bg-danger-50 p-3 text-danger-700">
            {{ $error }}
        </div>
    @endif

    @if (! empty($message))
        <div class="rounded-lg border border-info-300 bg-info-50 p-3 text-info-700">
            {{ $message }}
            @if (! empty($sample_length))
                <div class="mt-1 text-xs text-info-600">Fetched {{ number_format($sample_length) }} bytes.</div>
            @endif
        </div>
    @endif

    @if (! empty($headers ?? []))
        <div>
            <div class="font-medium text-gray-700">Detected columns</div>
            <div class="mt-1 flex flex-wrap gap-2">
                @foreach ($headers as $header)
                    <span class="rounded bg-gray-100 px-2 py-1 text-xs">{{ $header }}</span>
                @endforeach
            </div>
        </div>
    @endif

    @if (! empty($top_level_keys ?? []))
        <div>
            <div class="font-medium text-gray-700">Top-level JSON keys</div>
            <div class="mt-1 flex flex-wrap gap-2">
                @foreach ($top_level_keys as $key)
                    <span class="rounded bg-gray-100 px-2 py-1 text-xs">{{ $key }}</span>
                @endforeach
            </div>
        </div>
    @endif

    @if (! empty($sample_mappings ?? []))
        <div>
            <div class="font-medium text-gray-700">Column mapping samples</div>
            <div class="mt-2 overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200 text-xs">
                    <thead>
                        <tr>
                            <th class="px-2 py-1 text-left">Field</th>
                            <th class="px-2 py-1 text-left">Mapped column</th>
                            <th class="px-2 py-1 text-left">Samples</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @foreach ($sample_mappings as $field => $mapping)
                            @continue($field === '_warnings')
                            <tr>
                                <td class="px-2 py-1 font-medium">{{ $field }}</td>
                                <td class="px-2 py-1">{{ $mapping['column'] ?? '—' }}</td>
                                <td class="px-2 py-1">{{ implode(', ', $mapping['samples'] ?? []) ?: '—' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            @if (! empty($sample_mappings['_warnings']['samples']))
                <div class="mt-2 rounded border border-warning-300 bg-warning-50 p-2 text-warning-800">
                    {{ $sample_mappings['_warnings']['samples'][0] }}
                </div>
            @endif
        </div>
    @endif

    @if ($rowsToRender)
        @php($flat = $flatten($rowsToRender))
        <div>
            <div class="font-medium text-gray-700">{{ $rowsHeading }}</div>
            <div class="mt-2 max-h-80 overflow-auto rounded border border-gray-200">
                <table class="min-w-full divide-y divide-gray-200 text-xs">
                    <thead class="bg-gray-50">
                        <tr>
                            @foreach ($flat['keys'] as $column)
                                <th class="px-2 py-1 text-left">{{ $column }}</th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @foreach ($flat['rows'] as $row)
                            <tr>
                                @foreach ($row as $value)
                                    <td class="px-2 py-1 whitespace-nowrap">{{ $value ?? '—' }}</td>
                                @endforeach
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endif

    @if (! $error && ! $rowsToRender && empty($headers ?? []) && empty($message))
        <div class="rounded-lg border border-gray-200 bg-gray-50 p-3 text-gray-600">
            No sample rows were returned for this connector.
        </div>
    @endif
</div>
