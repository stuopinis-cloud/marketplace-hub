<?php

namespace App\Console\Commands;

use App\Services\Marketplace\Translations\TranslationRetryService;
use Illuminate\Console\Command;

class TranslationsRetryFailedCommand extends Command
{
    protected $signature = 'translations:retry-failed
                            {--marketplace=ebay : Marketplace code}
                            {--locale=en : Target locale}
                            {--reason= : Only retry rows whose error_message contains this text}
                            {--limit=100 : Max failed rows to retry}
                            {--dry-run : Preview without queueing}';

    protected $description = 'Re-queue failed marketplace translations in small batches';

    public function handle(TranslationRetryService $retry): int
    {
        $marketplace = (string) $this->option('marketplace');
        $locale = (string) $this->option('locale');
        $reason = filled($this->option('reason')) ? (string) $this->option('reason') : null;
        $limit = max(1, (int) $this->option('limit'));
        $dryRun = (bool) $this->option('dry-run');

        $result = $retry->retryFailed(
            marketplace: $marketplace,
            locale: $locale,
            reason: $reason,
            limit: $limit,
            dryRun: $dryRun,
        );

        if ($result['groups'] !== []) {
            $this->table(
                ['Field', 'Error', 'Count'],
                collect($result['groups'])->map(fn (array $group): array => [
                    $group['field'],
                    \Illuminate\Support\Str::limit($group['error'], 80),
                    $group['count'],
                ])->all(),
            );
        }

        if ($dryRun) {
            $this->components->info(sprintf(
                'Dry run: %d failed translation(s) would be retried for %s/%s.',
                $result['selected'],
                $marketplace,
                $locale,
            ));

            return self::SUCCESS;
        }

        $this->components->info(sprintf(
            'Queued %d failed translation(s) for %s/%s (selected %d, skipped %d).',
            $result['queued'],
            $marketplace,
            $locale,
            $result['selected'],
            $result['skipped'],
        ));

        return self::SUCCESS;
    }
}
