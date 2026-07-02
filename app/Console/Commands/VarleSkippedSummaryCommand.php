<?php

namespace App\Console\Commands;

use App\Enums\SyncJobItemStatus;
use App\Models\SyncJob;
use App\Models\SyncJobItem;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

class VarleSkippedSummaryCommand extends Command
{
    protected $signature = 'varle:skipped-summary {syncJobId? : Sync job ID to summarize}';

    protected $description = 'Summarize skipped and failed items from a Varle XML export';

    public function handle(): int
    {
        $syncJob = $this->resolveSyncJob($this->argument('syncJobId'));

        if ($syncJob === null) {
            $this->components->error('No Varle export sync job found.');

            return self::FAILURE;
        }

        $this->renderHeader($syncJob);

        $failedItems = $syncJob->items()
            ->with('product')
            ->where('status', SyncJobItemStatus::Failed)
            ->orderBy('id')
            ->get();

        $warnings = collect($syncJob->context['warnings'] ?? [])
            ->filter(fn ($warning) => is_string($warning) && $warning !== '')
            ->values();

        $this->renderSkippedItemsSummary($failedItems);
        $this->renderWarningsSummary($warnings);

        return self::SUCCESS;
    }

    private function resolveSyncJob(?string $syncJobId): ?SyncJob
    {
        if (filled($syncJobId)) {
            return SyncJob::query()->find($syncJobId);
        }

        return SyncJob::query()
            ->where('type', 'export')
            ->where('channel', 'varle')
            ->latest('id')
            ->first();
    }

    private function renderHeader(SyncJob $syncJob): void
    {
        $this->newLine();
        $this->components->info('Varle export skipped summary');
        $this->line('Sync job ID: '.$syncJob->id);
        $this->line('Status: '.($syncJob->status?->value ?? '—'));
        $this->line('Started at: '.($syncJob->started_at?->toDateTimeString() ?? '—'));
        $this->line('Finished at: '.($syncJob->finished_at?->toDateTimeString() ?? '—'));
        $this->line('Exported variants: '.(int) ($syncJob->context['exported_variants'] ?? 0));
        $this->line('Skipped variants (job counter): '.(int) $syncJob->failed_items);
    }

    /**
     * @param  Collection<int, SyncJobItem>  $failedItems
     */
    private function renderSkippedItemsSummary(Collection $failedItems): void
    {
        $this->newLine();
        $this->components->info('Skipped / failed items');
        $this->line('Total skipped/failed items: '.$failedItems->count());

        if ($failedItems->isEmpty()) {
            $this->line('No skipped or failed sync job items.');

            return;
        }

        foreach ($this->groupItemsByMessage($failedItems) as $group) {
            $this->newLine();
            $this->line($group['message']);
            $this->line('  Count: '.$group['count']);
            $this->line('  Examples: '.$this->formatExamples($group['examples']));
        }
    }

    /**
     * @param  Collection<int, string>  $warnings
     */
    private function renderWarningsSummary(Collection $warnings): void
    {
        $this->newLine();
        $this->components->info('Warnings');
        $this->line('Total warnings: '.$warnings->count());

        if ($warnings->isEmpty()) {
            $this->line('No warnings recorded for this export.');

            return;
        }

        foreach ($this->groupWarningsByMessage($warnings) as $group) {
            $this->newLine();
            $this->line($group['message']);
            $this->line('  Count: '.$group['count']);
            $this->line('  Examples: '.$this->formatExamples($group['examples']));
        }
    }

    /**
     * @param  Collection<int, SyncJobItem>  $items
     * @return array<int, array{message: string, count: int, examples: array<int, string>}>
     */
    private function groupItemsByMessage(Collection $items): array
    {
        return $items
            ->groupBy(fn (SyncJobItem $item): string => (string) ($item->message ?? 'Unknown'))
            ->map(function (Collection $group, string $message): array {
                return [
                    'message' => $message,
                    'count' => $group->count(),
                    'examples' => $group
                        ->map(fn (SyncJobItem $item): string => $this->exampleLabel($item))
                        ->unique()
                        ->values()
                        ->take(10)
                        ->all(),
                ];
            })
            ->sortByDesc('count')
            ->values()
            ->all();
    }

    /**
     * @param  Collection<int, string>  $warnings
     * @return array<int, array{message: string, count: int, examples: array<int, string>}>
     */
    private function groupWarningsByMessage(Collection $warnings): array
    {
        return $warnings
            ->countBy()
            ->map(function (int $count, string $message): array {
                return [
                    'message' => $message,
                    'count' => $count,
                    'examples' => [$message],
                ];
            })
            ->sortByDesc('count')
            ->values()
            ->take(50)
            ->all();
    }

    private function exampleLabel(SyncJobItem $item): string
    {
        if (filled($item->sku)) {
            return (string) $item->sku;
        }

        if (filled($item->product?->handle)) {
            return (string) $item->product->handle;
        }

        if ($item->product_id !== null) {
            return 'product#'.$item->product_id;
        }

        return 'item#'.$item->id;
    }

    /**
     * @param  array<int, string>  $examples
     */
    private function formatExamples(array $examples): string
    {
        if ($examples === []) {
            return '—';
        }

        return implode(', ', $examples);
    }
}
