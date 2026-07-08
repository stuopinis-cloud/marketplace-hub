<?php

namespace App\Console\Commands;

use App\Models\Product;
use App\Services\Marketplace\Varle\VarleVariantPresenter;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

class ShopifyDebugProductOptionsCommand extends Command
{
    protected $signature = 'shopify:debug-product-options {handle? : Product handle to inspect}';

    protected $description = 'Display Shopify product variant options stored in the local catalog';

    public function handle(): int
    {
        $handle = $this->argument('handle');

        $query = Product::query()
            ->with(['variants', 'images'])
            ->orderBy('id');

        if (filled($handle)) {
            $products = $query->where('handle', $handle)->get();

            if ($products->isEmpty()) {
                $this->components->error("Product not found for handle: {$handle}");

                return self::FAILURE;
            }
        } else {
            $products = $query->limit(20)->get();
        }

        if ($products->isEmpty()) {
            $this->components->warn('No products found in the catalog.');

            return self::SUCCESS;
        }

        foreach ($products as $product) {
            $this->renderProduct($product);
        }

        return self::SUCCESS;
    }

    private function renderProduct(Product $product): void
    {
        $this->newLine();
        $this->line('Product ID: '.$product->id);
        $this->line('Product handle: '.($product->handle ?? '—'));
        $this->line('Product title: '.$product->title);
        $this->line('Local variant count: '.$product->variants->count());

        $shopifyVariantCount = data_get($product->raw_payload, 'totalVariants');

        if ($shopifyVariantCount !== null) {
            $this->line('Shopify totalVariants (raw_payload): '.(int) $shopifyVariantCount);

            if ((int) $shopifyVariantCount > $product->variants->count()) {
                $this->components->warn(sprintf(
                    'Shopify reports %d variants but local DB has %d.',
                    (int) $shopifyVariantCount,
                    $product->variants->count(),
                ));
            }
        }

        $optionSummary = $this->detectOptionSummary($product);

        if ($optionSummary['names']->isNotEmpty()) {
            $this->line('Detected option names: '.$optionSummary['names']->implode(', '));

            foreach ($optionSummary['values_by_name'] as $name => $values) {
                $this->line("  {$name}: ".$values->implode(', '));
            }
        } else {
            $this->line('Detected option names: none');
        }

        $productOptions = data_get($product->raw_payload, 'options', []);

        if (is_array($productOptions) && $productOptions !== []) {
            $this->line('Product options (raw_payload):');

            foreach ($productOptions as $option) {
                if (! is_array($option)) {
                    continue;
                }

                $name = (string) ($option['name'] ?? '—');
                $values = $option['values'] ?? [];

                if (is_array($values) && $values !== []) {
                    $this->line("  - {$name}: ".implode(', ', array_map('strval', $values)));
                } else {
                    $this->line("  - {$name}");
                }
            }
        } else {
            $this->line('Product options (raw_payload): none');
        }

        if ($this->output->isVerbose()) {
            $this->renderProductImages($product);
        }

        if ($product->variants->isEmpty()) {
            $this->line('Variants: none');

            return;
        }

        foreach ($product->variants as $variant) {
            $this->newLine();
            $this->line('  Variant SKU: '.($variant->sku ?? '—'));
            $this->line('  Variant title: '.($variant->title ?? '—'));
            $this->line('  option1: '.($variant->option1 ?? '—'));
            $this->line('  option2: '.($variant->option2 ?? '—'));
            $this->line('  option3: '.($variant->option3 ?? '—'));
            $this->line('  option1_name: '.($variant->option1_name ?? '—'));
            $this->line('  option1_value: '.($variant->option1_value ?? '—'));
            $this->line('  option2_name: '.($variant->option2_name ?? '—'));
            $this->line('  option2_value: '.($variant->option2_value ?? '—'));
            $this->line('  option3_name: '.($variant->option3_name ?? '—'));
            $this->line('  option3_value: '.($variant->option3_value ?? '—'));
            $this->line('  barcode: '.($variant->barcode ?? '—'));
            $this->line('  inventory_policy: '.($variant->inventory_policy ?? '—'));
            $this->line('  backorder_allowed: '.($variant->backorder_allowed ? 'yes' : 'no'));
            $this->line('  image_url: '.(filled($variant->image_url) ? 'yes' : 'no'));

            if ($this->output->isVerbose() && filled($variant->image_url)) {
                $this->line('  image_url_value: '.$variant->image_url);
            }

            $selectedOptions = data_get($variant->raw_payload, 'selectedOptions');

            if (is_array($selectedOptions) && $selectedOptions !== []) {
                $this->line('  raw selectedOptions: '.json_encode($selectedOptions, JSON_UNESCAPED_UNICODE));
            } else {
                $this->line('  raw selectedOptions: none');
            }
        }
    }

    private function renderProductImages(Product $product): void
    {
        $analysis = VarleVariantPresenter::analyzeProductImages($product);

        $this->newLine();
        $this->line('Product images:');

        if ($analysis['product_images'] === []) {
            $this->line('  none');
        }

        foreach ($analysis['product_images'] as $image) {
            $this->line(sprintf(
                '  - [%s] %s (variant: %s)',
                $image['classification'],
                $image['url'],
                $image['variant_sku'] ?? 'unassigned',
            ));
        }

        $this->line('Variant image URLs:');

        foreach ($analysis['variant_images'] as $variantImage) {
            $color = filled($variantImage['color']) ? ' color='.$variantImage['color'] : '';
            $url = filled($variantImage['image_url']) ? $variantImage['image_url'] : 'none';

            $this->line(sprintf(
                '  - SKU %s%s: %s',
                $variantImage['sku'] ?? '—',
                $color,
                $url,
            ));
        }

        if ($analysis['forbidden_urls'] !== []) {
            $this->line('Forbidden variant image URLs (when exporting all groups together):');
            foreach ($analysis['forbidden_urls'] as $url) {
                $this->line('  - '.$url);
            }
        }
    }

    /**
     * @return array{names: Collection<int, string>, values_by_name: Collection<string, Collection<int, string>>}
     */
    private function detectOptionSummary(Product $product): array
    {
        $names = collect(data_get($product->raw_payload, 'options', []))
            ->filter(fn ($option) => is_array($option))
            ->pluck('name')
            ->filter()
            ->map(fn ($name) => (string) $name)
            ->values();

        $valuesByName = collect();

        foreach ($product->variants as $variant) {
            for ($index = 1; $index <= 3; $index++) {
                $name = $variant->{"option{$index}_name"};
                $value = $variant->{"option{$index}_value"} ?? $variant->{"option{$index}"};

                if (blank($name) || blank($value)) {
                    continue;
                }

                $name = (string) $name;
                $names = $names->push($name)->unique()->values();
                $valuesByName[$name] = collect($valuesByName->get($name, []))
                    ->push((string) $value)
                    ->unique()
                    ->values();
            }
        }

        return [
            'names' => $names->unique()->values(),
            'values_by_name' => $valuesByName,
        ];
    }
}
