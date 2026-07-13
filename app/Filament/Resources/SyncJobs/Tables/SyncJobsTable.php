<?php

namespace App\Filament\Resources\SyncJobs\Tables;

use App\Enums\SyncJobStatus;
use App\Filament\Resources\SyncJobs\Actions\CancelSyncJobAction;
use App\Filament\Resources\SyncJobs\Actions\CheckStuckSyncJobAction;
use App\Filament\Resources\SyncJobs\Actions\DownloadFailedCsvAction;
use App\Services\Sync\SyncJobHealthService;
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
        $healthService = app(SyncJobHealthService::class);
        $stuckMinutes = $healthService->stuckAfterMinutes();

        return $table
            ->columns([
                TextColumn::make('id')->sortable(),
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
                TextColumn::make('health')
                    ->label('Health')
                    ->state(fn ($record) => $healthService->assess($record)['label'])
                    ->badge()
                    ->color(fn ($record) => $healthService->assess($record)['color']),
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
                TextColumn::make('progress')
                    ->label('Progress')
                    ->state(fn ($record): string => $healthService->progressMetrics($record)['label']),
                TextColumn::make('process_id')
                    ->label('PID')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('heartbeat_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('heartbeat_age')
                    ->label('Heartbeat age')
                    ->state(fn ($record): string => $healthService->heartbeatAgeLabel($record))
                    ->toggleable(),
                TextColumn::make('context.current_product_handle')
                    ->label('Current product')
                    ->toggleable()
                    ->placeholder('—')
                    ->wrap(),
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
                TextColumn::make('duration')
                    ->label('Duration')
                    ->state(fn ($record): ?string => $healthService->durationLabel($record))
                    ->toggleable(),
                TextColumn::make('error_message')
                    ->limit(50)
                    ->tooltip(fn (?string $state): ?string => $state)
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('source')
                    ->options([
                        'shopify' => 'Shopify',
                        'supplier:mtac' => 'Supplier: M-Tac',
                        'supplier:helik' => 'Supplier: Helikon',
                    ]),
                SelectFilter::make('status')
                    ->options(SyncJobStatus::class),
                Filter::make('running')
                    ->label('Running')
                    ->query(fn (Builder $query): Builder => $query->where('status', SyncJobStatus::Running)),
                Filter::make('completed')
                    ->label('Completed')
                    ->query(fn (Builder $query): Builder => $query->whereIn('status', [
                        SyncJobStatus::Completed,
                        SyncJobStatus::Partial,
                    ])),
                Filter::make('failed')
                    ->label('Failed')
                    ->query(fn (Builder $query): Builder => $query->where('status', SyncJobStatus::Failed)),
                Filter::make('stuck')
                    ->label('Stuck')
                    ->query(function (Builder $query) use ($stuckMinutes): Builder {
                        $threshold = now()->subMinutes($stuckMinutes);

                        return $query
                            ->where('status', SyncJobStatus::Running)
                            ->where(function (Builder $inner) use ($threshold): void {
                                $inner->where('heartbeat_at', '<', $threshold)
                                    ->orWhere(function (Builder $nested) use ($threshold): void {
                                        $nested->whereNull('heartbeat_at')
                                            ->where('started_at', '<', $threshold);
                                    });
                            });
                    }),
                Filter::make('cancelled')
                    ->label('Cancelled')
                    ->query(fn (Builder $query): Builder => $query->where('status', SyncJobStatus::Cancelled)),
            ])
            ->recordActions([
                CheckStuckSyncJobAction::make(),
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
