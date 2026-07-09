<?php

namespace App\Filament\Resources\Products\Actions;

use App\Models\Product;
use App\Services\Marketplace\Varle\VarleReadinessService;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\HtmlString;

class VarleDiagnosticsAction
{
    public static function make(): Action
    {
        return Action::make('varleDiagnostics')
            ->label('Varle diagnostics')
            ->icon(Heroicon::OutlinedClipboardDocumentCheck)
            ->modalHeading('Varle export diagnostics')
            ->modalContent(function (Product $record): HtmlString {
                $analysis = app(VarleReadinessService::class)->analyze($record);

                $lines = [
                    '<strong>Product</strong>',
                    'Title: '.e($record->title),
                    'Handle: '.e((string) $record->handle),
                    'Vendor: '.e((string) $record->vendor),
                    'Product type: '.e((string) $record->product_type),
                    'Shopify status: '.e($record->status?->value ?? ''),
                    'Varle export status: '.e($record->varle_export_status?->value ?? ''),
                    'Ready for Varle: '.($analysis['is_ready_for_varle'] ? 'yes' : 'no'),
                    'Mapped category: '.e((string) ($analysis['mapped_category_preview'] ?? '—')),
                    'Delivery text preview: '.e((string) ($analysis['delivery_text_preview'] ?? '—')),
                    'Vendor delivery rule: '.e((string) ($analysis['delivery_rule']['status'] ?? '—')),
                    'Gate decision: '.e($analysis['gate_allowed'] ? 'allowed' : 'blocked').' — '.e((string) ($analysis['gate_message'] ?? '')),
                    'Issue codes: '.e(implode(', ', $analysis['issue_codes'] ?? [])),
                    '',
                    '<strong>Export structure</strong>',
                    'Structure: '.e((string) ($analysis['export_structure'] ?? '—')),
                    'Simple Shopify product: '.(($analysis['is_simple_shopify_product'] ?? false) ? 'yes' : 'no'),
                    'Will generate &lt;variants&gt;: '.(($analysis['will_generate_variants_block'] ?? false) ? 'yes' : 'no'),
                    'Shopify total variants: '.(int) ($analysis['shopify_total_variants'] ?? 0),
                    'Included variants: '.(int) ($analysis['included_variants_count'] ?? 0),
                    'Meaningful options: '.e(self::formatMeaningfulOptions($analysis['meaningful_options'] ?? [])),
                    '',
                    '<strong>Variants</strong>',
                ];

                foreach ($analysis['variant_diagnostics'] as $variant) {
                    $lines[] = sprintf(
                        '- %s | barcode: %s | qty: %s | image: %s | exportable: %s | delivery: %s | %s',
                        e((string) ($variant['sku'] ?? '—')),
                        e((string) ($variant['barcode'] ?? '—')),
                        e((string) $variant['quantity']),
                        $variant['has_variant_image'] ? 'yes' : 'no',
                        $variant['exportable'] ? 'yes' : 'no',
                        e((string) $variant['delivery_class']),
                        e((string) ($variant['skipped_reason'] ?? '')),
                    );
                }

                $imageResolution = $analysis['image_resolution'] ?? [];
                $lines[] = '';
                $lines[] = '<strong>Images</strong>';
                $lines[] = 'Selected export images: '.(int) ($imageResolution['urls'] ? count($imageResolution['urls']) : 0);
                $lines[] = 'Variant images: '.(int) ($imageResolution['variant_images_count'] ?? 0);
                $lines[] = 'Generic gallery images: '.(int) ($imageResolution['generic_gallery_images_count'] ?? 0);
                $lines[] = 'Forbidden variant images: '.(int) ($imageResolution['forbidden_variant_images_count'] ?? 0);

                $lines[] = '';
                $lines[] = '<strong>XML preview IDs</strong>';
                $lines[] = e(implode(', ', $analysis['generated_product_ids'] ?? []));

                return new HtmlString('<div style="font-family: monospace; white-space: pre-wrap;">'.implode("\n", $lines).'</div>');
            })
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('Close');
    }

    /**
     * @param  array<int, array{name?: string, values?: array<int, string>}>  $meaningfulOptions
     */
    private static function formatMeaningfulOptions(array $meaningfulOptions): string
    {
        if ($meaningfulOptions === []) {
            return 'none';
        }

        return collect($meaningfulOptions)
            ->map(function (array $option): string {
                $values = implode(', ', $option['values'] ?? []);

                return ($option['name'] ?? '—').': '.$values;
            })
            ->implode('; ');
    }
}
