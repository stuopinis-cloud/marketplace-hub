<?php

namespace App\Filament\Resources\CategoryMappings\Tables;

use App\Models\CategoryMapping;
use App\Services\Marketplace\CategoryResolver;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class CategoryMappingsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('marketplaceChannel.name')
                    ->label('Marketplace channel')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('source_type')
                    ->badge()
                    ->sortable(),
                TextColumn::make('source_value')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('matching_products_count')
                    ->label('Matching products')
                    ->numeric()
                    ->getStateUsing(fn (CategoryMapping $record): int => app(CategoryResolver::class)->countMatchingProducts($record)),
                TextColumn::make('target_category_path')
                    ->searchable()
                    ->wrap(),
                TextColumn::make('priority')
                    ->sortable(),
                IconColumn::make('enabled')
                    ->boolean(),
                IconColumn::make('export_enabled')
                    ->label('Export enabled')
                    ->boolean(),
                TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('marketplace_channel_id')
                    ->relationship('marketplaceChannel', 'name')
                    ->label('Channel'),
                SelectFilter::make('source_type')
                    ->options([
                        'collection' => 'Collection',
                        'product_type' => 'Product type',
                        'tag' => 'Tag',
                        'manual' => 'Manual',
                    ]),
                TernaryFilter::make('enabled'),
                TernaryFilter::make('export_enabled')
                    ->label('Export enabled'),
            ])
            ->defaultSort('priority');
    }
}
