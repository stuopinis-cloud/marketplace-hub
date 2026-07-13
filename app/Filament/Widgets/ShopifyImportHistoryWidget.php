<?php

namespace App\Filament\Widgets;

use App\Enums\SyncJobStatus;
use App\Filament\Resources\SyncJobs\Actions\CancelSyncJobAction;
use App\Filament\Resources\SyncJobs\Actions\CheckStuckSyncJobAction;
use App\Filament\Resources\SyncJobs\SyncJobResource;
use App\Models\SyncJob;
use App\Services\Sync\SyncJobHealthService;
use Filament\Actions\Action;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;

class ShopifyImportHistoryWidget extends TableWidget
{
    protected static ?string $heading = 'Recent Shopify imports';

    protected static ?int $sort = 2;

    protected int|string|array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        $healthService = app(SyncJobHealthService::class);
        $stuckMinutes = $healthService->stuckAfterMinutes();

        return $table
            ->query(
                SyncJob::query()
                    ->where('type', 'import')
                    ->where('source', 'shopify')
                    ->latest('id')
                    ->limit(20),
            )
            ->paginated(false)
            ->columns([
                TextColumn::make('id')->sortable(),
                TextColumn::make('status')->badge()->sortable(),
                TextColumn::make('health')
                    ->label('Health')
                    ->state(function (SyncJob $record) use ($healthService): string {
                        return $healthService->assess($record)['label'];
                    })
                    ->badge()
                    ->color(function (SyncJob $record) use ($healthService): string {
                        return $healthService->assess($record)['color'];
                    }),
                TextColumn::make('started_at')->dateTime()->sortable(),
                TextColumn::make('finished_at')->dateTime()->sortable(),
                TextColumn::make('duration')
                    ->label('Duration')
                    ->state(fn (SyncJob $record): ?string => $healthService->durationLabel($record)),
                TextColumn::make('total_items')->label('Total'),
                TextColumn::make('success_items')->label('OK'),
                TextColumn::make('failed_items')->label('Failed')->color(fn (int $state): string => $state > 0 ? 'danger' : 'gray'),
                TextColumn::make('progress')
                    ->label('Progress')
                    ->state(fn (SyncJob $record): string => $healthService->progressMetrics($record)['label']),
                TextColumn::make('heartbeat_at')->dateTime(),
                TextColumn::make('heartbeat_age')
                    ->label('Heartbeat age')
                    ->state(fn (SyncJob $record): string => $healthService->heartbeatAgeLabel($record)),
                TextColumn::make('context.current_product_handle')
                    ->label('Current product')
                    ->placeholder('—')
                    ->wrap(),
                TextColumn::make('error_message')
                    ->limit(40)
                    ->tooltip(fn (?string $state): ?string => $state)
                    ->placeholder('—'),
            ])
            ->recordActions([
                Action::make('view')
                    ->label('View details')
                    ->icon('heroicon-o-eye')
                    ->url(fn (SyncJob $record): string => SyncJobResource::getUrl('edit', ['record' => $record])),
                CheckStuckSyncJobAction::make(),
                CancelSyncJobAction::make(),
            ])
            ->filters([
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
            ]);
    }
}
