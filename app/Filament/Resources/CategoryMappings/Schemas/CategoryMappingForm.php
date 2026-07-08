<?php

namespace App\Filament\Resources\CategoryMappings\Schemas;

use App\Models\SourceCategory;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;

class CategoryMappingForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('marketplace_channel_id')
                    ->relationship('marketplaceChannel', 'name')
                    ->searchable()
                    ->preload()
                    ->required(),
                Select::make('source_type')
                    ->options([
                        'collection' => 'Collection',
                        'product_type' => 'Product type',
                        'tag' => 'Tag',
                        'manual' => 'Manual',
                    ])
                    ->required()
                    ->live()
                    ->afterStateUpdated(function (Set $set): void {
                        $set('source_category_id', null);
                        $set('source_value', null);
                        $set('use_manual_source_value', false);
                    }),
                Select::make('source_category_id')
                    ->label('Source')
                    ->searchable()
                    ->getSearchResultsUsing(function (string $search, Get $get): array {
                        $type = $get('source_type');

                        if (! in_array($type, ['collection', 'product_type', 'tag'], true)) {
                            return [];
                        }

                        return SourceCategory::query()
                            ->where('type', $type)
                            ->where(function ($query) use ($search): void {
                                $query
                                    ->where('name', 'ilike', "%{$search}%")
                                    ->orWhere('handle', 'ilike', "%{$search}%");
                            })
                            ->orderBy('name')
                            ->limit(50)
                            ->get()
                            ->mapWithKeys(fn (SourceCategory $category): array => [
                                $category->id => $category->selectLabel(),
                            ])
                            ->all();
                    })
                    ->getOptionLabelUsing(fn ($value): ?string => SourceCategory::query()->find($value)?->selectLabel())
                    ->visible(fn (Get $get): bool => self::usesSourceCategoryPicker($get) && ! $get('use_manual_source_value'))
                    ->required(fn (Get $get): bool => self::usesSourceCategoryPicker($get) && ! $get('use_manual_source_value'))
                    ->live()
                    ->afterStateUpdated(function (?string $state, Set $set): void {
                        $category = SourceCategory::query()->find($state);

                        if ($category !== null) {
                            $set('source_value', $category->mappingSourceValue());
                        }
                    }),
                Toggle::make('use_manual_source_value')
                    ->label('Enter source value manually')
                    ->visible(fn (Get $get): bool => self::usesSourceCategoryPicker($get))
                    ->live()
                    ->dehydrated(false),
                TextInput::make('source_value')
                    ->required(fn (Get $get): bool => self::usesManualSourceValue($get))
                    ->maxLength(255)
                    ->dehydrated()
                    ->hidden(fn (Get $get): bool => self::usesSourceCategoryPicker($get) && ! $get('use_manual_source_value'))
                    ->visible(fn (Get $get): bool => self::usesManualSourceValue($get))
                    ->helperText(fn (Get $get): ?string => self::usesSourceCategoryPicker($get) && $get('use_manual_source_value')
                        ? 'Override the selected source with a custom value if needed.'
                        : ($get('source_type') === 'manual'
                            ? 'Enter the exact product.category value to match.'
                            : 'For Shopify collections, enter either the collection name (e.g. "Pirštinės") or handle (e.g. "pirstines"). For product type or tag mappings, enter the exact Shopify value.')),
                TextInput::make('target_category_path')
                    ->required()
                    ->maxLength(255)
                    ->helperText('Example: Taktinė ekipuotė -> Šarvinės liemenės'),
                TextInput::make('priority')
                    ->numeric()
                    ->default(100)
                    ->required()
                    ->helperText('Lower numbers win when multiple mappings match.'),
                Toggle::make('enabled')
                    ->default(true),
                Toggle::make('export_enabled')
                    ->label('Export enabled')
                    ->default(true)
                    ->helperText('When disabled, auto products mapped to this category are skipped from Varle XML.'),
            ]);
    }

    public static function usesSourceCategoryPicker(Get $get): bool
    {
        return in_array($get('source_type'), ['collection', 'product_type', 'tag'], true);
    }

    public static function usesManualSourceValue(Get $get): bool
    {
        if ($get('source_type') === 'manual') {
            return true;
        }

        return self::usesSourceCategoryPicker($get) && (bool) $get('use_manual_source_value');
    }
}
