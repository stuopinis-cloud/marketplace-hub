<?php

namespace App\Services\Marketplace\Varle;

use App\Models\Product;
use App\Models\VendorDeliveryRule;
use App\Support\MarketplaceChannelConfig;

class VarleDeliveryResolver
{
    /**
     * @param  array<string, mixed>  $channelConfig
     * @return array{
     *     vendor: ?string,
     *     rule_id: ?int,
     *     status: string,
     *     in_stock_delivery_text: string,
     *     backorder_delivery_text: string,
     *     allow_backorder_export: bool,
     *     enabled: bool
     * }
     */
    public function resolveForProduct(Product $product, array $channelConfig): array
    {
        $config = MarketplaceChannelConfig::for($channelConfig);
        $defaults = $this->channelDefaults($config);
        $vendor = filled($product->vendor) ? trim((string) $product->vendor) : null;

        if ($vendor !== null) {
            $rule = VendorDeliveryRule::query()
                ->where('enabled', true)
                ->where('vendor', $vendor)
                ->orderBy('priority')
                ->orderBy('id')
                ->first();

            if ($rule !== null) {
                return $this->rulePayload($rule, 'vendor_rule_found', $vendor);
            }
        }

        $defaultRule = VendorDeliveryRule::query()
            ->where('enabled', true)
            ->where('vendor', VendorDeliveryRule::DEFAULT_VENDOR)
            ->orderBy('priority')
            ->orderBy('id')
            ->first();

        if ($defaultRule !== null) {
            return $this->rulePayload($defaultRule, 'default_rule_used', $vendor);
        }

        return [
            'vendor' => $vendor,
            'rule_id' => null,
            'status' => 'default_rule_used',
            'in_stock_delivery_text' => $defaults['in_stock_delivery_text'],
            'backorder_delivery_text' => $defaults['backorder_delivery_text'],
            'allow_backorder_export' => $defaults['allow_backorder_export'],
            'enabled' => true,
        ];
    }

    /**
     * @param  array<int, string>  $deliveryClasses
     * @param  array<int, string>  $deliveryTexts
     */
    public function resolveProductDeliveryText(array $deliveryRule, array $deliveryClasses, array $deliveryTexts = []): string
    {
        if ($deliveryTexts !== [] && $deliveryClasses !== []) {
            return $this->resolveSlowestDeliveryText($deliveryTexts, $deliveryClasses);
        }

        if (in_array(VarleStockEvaluator::CLASS_BACKORDER, $deliveryClasses, true)) {
            return (string) $deliveryRule['backorder_delivery_text'];
        }

        return (string) $deliveryRule['in_stock_delivery_text'];
    }

    /**
     * @param  array<int, string>  $deliveryTexts
     * @param  array<int, string>  $deliveryClasses
     */
    private function resolveSlowestDeliveryText(array $deliveryTexts, array $deliveryClasses): string
    {
        $rank = [
            VarleStockEvaluator::CLASS_IN_STOCK => 1,
            VarleStockEvaluator::CLASS_SUPPLIER => 2,
            VarleStockEvaluator::CLASS_BACKORDER => 3,
        ];

        $slowestIndex = 0;
        $slowestRank = 0;

        foreach ($deliveryClasses as $index => $class) {
            $classRank = $rank[$class] ?? 1;
            if ($classRank >= $slowestRank) {
                $slowestRank = $classRank;
                $slowestIndex = $index;
            }
        }

        $selected = $deliveryTexts[$slowestIndex] ?? null;

        if (filled($selected)) {
            return (string) $selected;
        }

        return (string) ($deliveryTexts[$slowestIndex] ?? $deliveryTexts[0] ?? '');
    }

    /**
     * @return array{
     *     in_stock_delivery_text: string,
     *     backorder_delivery_text: string,
     *     allow_backorder_export: bool
     * }
     */
    private function channelDefaults(MarketplaceChannelConfig $config): array
    {
        return [
            'in_stock_delivery_text' => $config->string('delivery_in_stock_text')
                ?? $config->string('delivery_text')
                ?? (string) config('marketplace.exports.varle.default_delivery_text', '1-2 d.d.'),
            'backorder_delivery_text' => $config->string('delivery_backorder_text', '5-10 d.d.') ?? '5-10 d.d.',
            'allow_backorder_export' => $config->bool('allow_backorder_export', true),
        ];
    }

    /**
     * @return array{
     *     vendor: ?string,
     *     rule_id: int,
     *     status: string,
     *     in_stock_delivery_text: string,
     *     backorder_delivery_text: string,
     *     allow_backorder_export: bool,
     *     enabled: bool
     * }
     */
    private function rulePayload(VendorDeliveryRule $rule, string $status, ?string $vendor): array
    {
        return [
            'vendor' => $vendor ?? $rule->vendor,
            'rule_id' => $rule->id,
            'status' => $status,
            'in_stock_delivery_text' => $rule->in_stock_delivery_text,
            'backorder_delivery_text' => $rule->backorder_delivery_text,
            'allow_backorder_export' => $rule->allow_backorder_export,
            'enabled' => $rule->enabled,
        ];
    }
}
