<?php

namespace App\Services\Marketplace\Varle;

use App\Models\CategoryMapping;
use App\Models\MarketplaceChannel;
use App\Models\VendorDeliveryRule;
use Illuminate\Support\Collection;

class VarleReadinessRunContext
{
    /**
     * @param  array<string, mixed>  $channelConfig
     * @param  Collection<int, CategoryMapping>  $categoryMappings
     * @param  array<string, VendorDeliveryRule>  $vendorRulesByVendor
     */
    public function __construct(
        public readonly MarketplaceChannel $channel,
        public readonly array $channelConfig,
        public readonly Collection $categoryMappings,
        public readonly array $vendorRulesByVendor,
        public readonly ?VendorDeliveryRule $defaultVendorRule,
    ) {}
}
