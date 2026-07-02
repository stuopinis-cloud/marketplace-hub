<?php

namespace App\Filament\Resources\Products\Schemas;

use App\Enums\ProductStatus;
use App\Enums\VarleExportStatus;
use App\Models\MarketplaceChannel;
use App\Models\Product;
use App\Services\Marketplace\CategoryResolver;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class ProductForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('source_id')
                    ->relationship('source', 'name')
                    ->searchable()
                    ->preload(),
                TextInput::make('title')
                    ->required()
                    ->maxLength(255)
                    ->columnSpanFull(),
                TextInput::make('external_id')
                    ->maxLength(255),
                TextInput::make('handle')
                    ->maxLength(255),
                Select::make('status')
                    ->options(ProductStatus::class)
                    ->default(ProductStatus::Active)
                    ->required(),
                Select::make('varle_export_status')
                    ->label('Varle export status')
                    ->options(VarleExportStatus::class)
                    ->default(VarleExportStatus::PendingReview)
                    ->required()
                    ->helperText('Pending review products are imported but excluded from Varle XML until approved.'),
                TextInput::make('vendor')
                    ->maxLength(255),
                TextInput::make('brand')
                    ->maxLength(255),
                TextInput::make('product_type')
                    ->maxLength(255),
                TextInput::make('category')
                    ->maxLength(255),
                Placeholder::make('imported_source_categories')
                    ->label('Imported source categories')
                    ->content(function (?Product $record): string {
                        if (! $record) {
                            return '—';
                        }

                        $record->loadMissing('sourceCategories');

                        if ($record->sourceCategories->isEmpty()) {
                            return 'No imported source categories. Re-run Shopify import.';
                        }

                        return $record->sourceCategories
                            ->sortBy('type')
                            ->map(function ($category): string {
                                if ($category->type === 'collection') {
                                    return sprintf(
                                        'collection: %s (handle: %s)',
                                        $category->name,
                                        $category->handle ?: '—',
                                    );
                                }

                                return sprintf('%s: %s', $category->type, $category->name);
                            })
                            ->implode(PHP_EOL);
                    })
                    ->visible(fn (?Product $record): bool => $record !== null)
                    ->columnSpanFull(),
                Placeholder::make('resolved_varle_category')
                    ->label('Resolved Varle category')
                    ->content(function (?Product $record): string {
                        if (! $record) {
                            return '—';
                        }

                        $channel = MarketplaceChannel::query()->where('type', 'varle')->first();

                        if (! $channel) {
                            return 'Varle channel not configured';
                        }

                        $record->loadMissing('sourceCategories');
                        $explanation = app(CategoryResolver::class)->explain($record, $channel);

                        if (blank($explanation['resolved_category'])) {
                            return 'Not resolved';
                        }

                        $suffix = $explanation['source'] === 'mapping'
                            ? ' (via mapping)'
                            : ($explanation['fallback_used'] ? ' (fallback)' : '');

                        return $explanation['resolved_category'].$suffix;
                    })
                    ->visible(fn (?Product $record): bool => $record !== null)
                    ->columnSpanFull(),
                RichEditor::make('description_html')
                    ->columnSpanFull(),
                DateTimePicker::make('imported_at'),
                Textarea::make('raw_payload')
                    ->formatStateUsing(fn ($state) => is_array($state) ? json_encode($state, JSON_PRETTY_PRINT) : $state)
                    ->dehydrateStateUsing(fn ($state) => blank($state) ? null : json_decode($state, true))
                    ->rows(6)
                    ->columnSpanFull(),
            ]);
    }
}
