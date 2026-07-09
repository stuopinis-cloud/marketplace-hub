<div class="space-y-4 text-sm text-gray-700 dark:text-gray-200">
    @if ($error)
        <div class="rounded-lg border border-danger-300 bg-danger-50 p-4 text-danger-700 dark:border-danger-500/40 dark:bg-danger-500/10 dark:text-danger-300">
            {{ $error }}
        </div>
    @else
        @if ($summary)
            <div class="grid gap-2 sm:grid-cols-3 lg:grid-cols-6">
                <div><span class="font-medium">Total:</span> {{ $summary->totalRows }}</div>
                <div><span class="font-medium">Create:</span> {{ $summary->created }}</div>
                <div><span class="font-medium">Update:</span> {{ $summary->updated }}</div>
                <div><span class="font-medium">Unchanged:</span> {{ $summary->unchanged }}</div>
                <div><span class="font-medium">Skipped:</span> {{ $summary->skipped }}</div>
                <div><span class="font-medium">Failed:</span> {{ $summary->failed }}</div>
            </div>
        @endif

        <div class="overflow-x-auto rounded-xl border border-gray-200 dark:border-white/10">
            <table class="min-w-full divide-y divide-gray-200 dark:divide-white/10">
                <thead class="bg-gray-50 dark:bg-white/5">
                    <tr>
                        <th class="px-3 py-2 text-left font-medium">Collection</th>
                        <th class="px-3 py-2 text-left font-medium">Handle</th>
                        <th class="px-3 py-2 text-left font-medium">Varle category</th>
                        <th class="px-3 py-2 text-left font-medium">Action</th>
                        <th class="px-3 py-2 text-left font-medium">Message</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200 dark:divide-white/10">
                    @forelse ($rows as $row)
                        <tr>
                            <td class="px-3 py-2 align-top">{{ $row['shopify_collection'] }}</td>
                            <td class="px-3 py-2 align-top font-mono">{{ $row['shopify_handle'] }}</td>
                            <td class="px-3 py-2 align-top">{{ $row['varle_final_category'] }}</td>
                            <td class="px-3 py-2 align-top">
                                <span @class([
                                    'inline-flex rounded-full px-2 py-0.5 text-xs font-medium',
                                    'bg-success-100 text-success-700 dark:bg-success-500/10 dark:text-success-300' => $row['action'] === 'create',
                                    'bg-warning-100 text-warning-700 dark:bg-warning-500/10 dark:text-warning-300' => $row['action'] === 'update',
                                    'bg-gray-100 text-gray-700 dark:bg-white/10 dark:text-gray-300' => $row['action'] === 'unchanged',
                                    'bg-danger-100 text-danger-700 dark:bg-danger-500/10 dark:text-danger-300' => in_array($row['action'], ['skip', 'error'], true),
                                ])>
                                    {{ $row['action'] }}
                                </span>
                            </td>
                            <td class="px-3 py-2 align-top text-gray-500 dark:text-gray-400">{{ $row['message'] }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="px-3 py-4 text-center text-gray-500 dark:text-gray-400">No rows to preview.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    @endif
</div>
