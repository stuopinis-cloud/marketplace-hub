<?php

namespace App\Console\Commands;

use App\Enums\MarketplaceTranslationStatus;
use App\Models\MarketplaceTranslation;
use App\Services\Marketplace\Ebay\EbayReadinessService;
use Illuminate\Console\Command;

class TranslationsStatusCommand extends Command
{
    protected $signature = 'translations:status
                            {--marketplace=ebay : Marketplace code}
                            {--locale=en : Target locale}';

    protected $description = 'Show marketplace translation status summary';

    public function handle(EbayReadinessService $readiness): int
    {
        $marketplace = (string) $this->option('marketplace');
        $locale = (string) $this->option('locale');

        $counts = MarketplaceTranslation::query()
            ->where('marketplace', $marketplace)
            ->where('locale', $locale)
            ->selectRaw('status, count(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status')
            ->all();

        $this->table(['Status', 'Count'], collect(MarketplaceTranslationStatus::cases())->map(
            fn (MarketplaceTranslationStatus $status): array => [
                $status->value,
                (int) ($counts[$status->value] ?? 0),
            ],
        )->all());

        $summary = $readiness->translationStatusSummary($marketplace, $locale);
        $this->line('Missing titles: '.$summary['missing_title']);
        $this->line('Missing descriptions: '.$summary['missing_description']);
        $this->line('Failed: '.$summary['failed']);
        $this->line('Approved: '.$summary['approved']);

        return self::SUCCESS;
    }
}
