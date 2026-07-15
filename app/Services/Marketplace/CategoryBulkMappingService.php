<?php

namespace App\Services\Marketplace;

use App\Models\CategoryMapping;
use App\Models\MarketplaceChannel;
use App\Models\SourceCategory;

class CategoryBulkMappingService
{
    /**
     * @param  array<int, int>  $categoryIds
     */
    public function applyMapping(
        array $categoryIds,
        string $targetCategoryPath,
        ?int $marketplaceChannelId = null,
    ): int {
        $categoryIds = array_values(array_unique(array_filter(array_map(
            fn (mixed $id): int => (int) $id,
            $categoryIds,
        ))));

        if ($categoryIds === [] || blank($targetCategoryPath)) {
            return 0;
        }

        $channelId = $marketplaceChannelId ?? MarketplaceChannel::query()->where('type', 'varle')->value('id');

        if ($channelId === null) {
            return 0;
        }

        $categories = SourceCategory::query()->whereIn('id', $categoryIds)->get();
        $created = 0;

        foreach ($categories as $category) {
            CategoryMapping::query()->updateOrCreate(
                [
                    'marketplace_channel_id' => $channelId,
                    'source_type' => 'collection',
                    'source_value' => $category->mappingSourceValue(),
                ],
                [
                    'target_category_path' => $targetCategoryPath,
                    'priority' => 100,
                    'enabled' => true,
                    'export_enabled' => true,
                ],
            );

            $created++;
        }

        return $created;
    }
}
