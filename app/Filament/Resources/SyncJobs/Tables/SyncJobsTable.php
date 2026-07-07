<?php

namespace App\Filament\Resources\SyncJobs\Tables;

use App\Enums\SyncJobStatus;
use App\Filament\Resources\SyncJobs\Actions\CancelSyncJobAction;
use App\Filament\Resources\SyncJobs\Actions\DownloadFailedCsvAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class SyncJobsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('type')
                    ->badge()
                    ->searchable()
                    ->sortable(),
                TextColumn::make('source')
                    ->searchable()
                    ->toggleable(),
                TextColumn::make('channel')
                    ->searchable()
                    ->toggleable(),
                TextColumn::make('status')
                    ->badge()
                    ->sortable(),
                TextColumn::make('total_items')
                    ->label('Total')
                    ->sortable(),
                TextColumn::make('success_items')
                    ->label('OK')
                    ->sortable(),
                TextColumn::make('failed_items')
                    ->label('Failed')
                    ->sortable()
                    ->color(fn (int $state): string => $state > 0 ? 'danger' : 'gray'),
                TextColumn::make('process_id')
                    ->label('PID')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('heartbeat_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('context.current_product_handle')
                    ->label('Current product')
                    ->toggleable()
                    ->placeholder('—'),
                TextColumn::make('context.stage')
                    ->label('Stage')
                    ->badge()
                    ->toggleable(),
                TextColumn::make('started_at')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('finished_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options(SyncJobStatus::class),
                Filter::make('failed')
                    ->label('Failed jobs')
                    ->query(fn (Builder $query): Builder => $query->where('status', SyncJobStatus::Failed)),
            ])
            ->recordActions([
                CancelSyncJobAction::make(),
                DownloadFailedCsvAction::make(),
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('created_at', 'desc');
    }
}
