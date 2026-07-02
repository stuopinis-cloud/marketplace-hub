<?php

namespace App\Filament\Resources\FeedFiles\Tables;

use App\Enums\FeedFileStatus;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class FeedFilesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('filename')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('marketplaceChannel.name')
                    ->label('Channel')
                    ->sortable()
                    ->searchable(),
                TextColumn::make('status')
                    ->badge()
                    ->sortable(),
                TextColumn::make('path')
                    ->limit(40)
                    ->toggleable(),
                TextColumn::make('public_url')
                    ->url(fn ($record) => $record->public_url)
                    ->openUrlInNewTab()
                    ->limit(40)
                    ->toggleable(),
                TextColumn::make('generated_at')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options(FeedFileStatus::class),
                SelectFilter::make('marketplace_channel_id')
                    ->relationship('marketplaceChannel', 'name')
                    ->searchable()
                    ->preload(),
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('generated_at', 'desc');
    }
}
