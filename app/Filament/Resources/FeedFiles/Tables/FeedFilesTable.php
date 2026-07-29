<?php

namespace App\Filament\Resources\FeedFiles\Tables;

use App\Enums\FeedFileStatus;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\Action;
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
                TextColumn::make('file_exists')
                    ->label('File exists')
                    ->state(fn ($record): string => Storage::disk('public')->exists($record->path) ? 'yes' : 'no')
                    ->badge()
                    ->color(fn (string $state): string => $state === 'yes' ? 'success' : 'danger')
                    ->sortable(),
                TextColumn::make('file_size_bytes')
                    ->label('Size')
                    ->state(fn ($record): ?int => Storage::disk('public')->exists($record->path) ? (int) Storage::disk('public')->size($record->path) : null)
                    ->formatStateUsing(fn (?int $state): string => $state === null ? '—' : (string) $state)
                    ->toggleable(),
                TextColumn::make('file_last_modified')
                    ->label('Last modified')
                    ->state(fn ($record): ?int => Storage::disk('public')->exists($record->path) ? (int) Storage::disk('public')->lastModified($record->path) : null)
                    ->formatStateUsing(fn (?int $state): string => $state === null ? '—' : \Carbon\Carbon::createFromTimestamp((int) $state)->toDateTimeString())
                    ->toggleable(),
                TextColumn::make('path')
                    ->limit(40)
                    ->toggleable(),
                TextColumn::make('public_url')
                    ->url(fn ($record) => $record->public_url)
                    ->openUrlInNewTab()
                    ->copyable()
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
                Action::make('regeneratePiguProducts')
                    ->label('Regenerate products')
                    ->visible(fn ($record): bool => $record->marketplaceChannel?->type === 'pigu' && $record->filename === 'pigu-products.xml')
                    ->requiresConfirmation()
                    ->action(function ($record): void {
                        Artisan::call('pigu:export-products-xml', ['--easy-only' => true]);
                    }),
                Action::make('regeneratePiguOffers')
                    ->label('Regenerate offers')
                    ->visible(fn ($record): bool => $record->marketplaceChannel?->type === 'pigu' && $record->filename === 'pigu-offers.xml')
                    ->requiresConfirmation()
                    ->action(function ($record): void {
                        Artisan::call('pigu:export-offers-xml');
                    }),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('generated_at', 'desc');
    }
}
