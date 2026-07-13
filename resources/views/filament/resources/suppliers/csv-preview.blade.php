<div class="space-y-4 text-sm">
    @if ($error)
        <div class="rounded-lg border border-danger-300 bg-danger-50 p-3 text-danger-700">
            {{ $error }}
        </div>
    @endif

    @if (($headers ?? []) !== [])
        <div>
            <div class="font-medium text-gray-700">Detected columns</div>
            <div class="mt-1 flex flex-wrap gap-2">
                @foreach ($headers as $header)
                    <span class="rounded bg-gray-100 px-2 py-1 text-xs">{{ $header }}</span>
                @endforeach
            </div>
        </div>
    @endif

    @if (($sample_mappings ?? []) !== [])
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

    @if (($preview_rows ?? []) !== [])
        <div>
            <div class="font-medium text-gray-700">First {{ count($preview_rows) }} rows</div>
            <div class="mt-2 max-h-80 overflow-auto rounded border border-gray-200">
                <table class="min-w-full divide-y divide-gray-200 text-xs">
                    <thead class="bg-gray-50">
                        <tr>
                            @foreach (array_keys($preview_rows[0] ?? []) as $column)
                                <th class="px-2 py-1 text-left">{{ $column }}</th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @foreach ($preview_rows as $row)
                            <tr>
                                @foreach ($row as $value)
                                    <td class="px-2 py-1 whitespace-nowrap">{{ $value }}</td>
                                @endforeach
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endif
</div>
