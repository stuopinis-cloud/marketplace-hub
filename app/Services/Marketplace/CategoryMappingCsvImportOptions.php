<?php

namespace App\Services\Marketplace;

use App\Models\MarketplaceChannel;

class CategoryMappingCsvImportOptions
{
    public function __construct(
        public int $marketplaceChannelId,
        public string $sourceType = 'collection',
        public int $priority = 100,
        public bool $enabled = true,
        public bool $exportEnabled = true,
        public bool $dryRun = false,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            marketplaceChannelId: (int) $data['marketplace_channel_id'],
            sourceType: (string) ($data['source_type'] ?? 'collection'),
            priority: (int) ($data['priority'] ?? 100),
            enabled: (bool) ($data['enabled'] ?? true),
            exportEnabled: (bool) ($data['export_enabled'] ?? true),
            dryRun: (bool) ($data['dry_run'] ?? false),
        );
    }

    public static function resolveChannel(?int $channelId = null, ?string $channelIdentifier = 'varle'): MarketplaceChannel
    {
        if ($channelId !== null) {
            return MarketplaceChannel::query()->findOrFail($channelId);
        }

        $channel = MarketplaceChannel::query()
            ->when(
                $channelIdentifier !== null && $channelIdentifier !== '',
                fn ($query) => $query->where(function ($query) use ($channelIdentifier): void {
                    $query
                        ->where('type', $channelIdentifier)
                        ->orWhere('name', $channelIdentifier);
                }),
            )
            ->orderBy('id')
            ->first();

        if ($channel === null) {
            throw new \InvalidArgumentException('Varle marketplace channel was not found.');
        }

        return $channel;
    }
}
