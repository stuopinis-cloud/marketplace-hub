<?php

namespace App\Filament\Pages\VarleReadiness\Widgets;

use App\Models\SyncJobItem;
use App\Services\Marketplace\Varle\VarleReadinessMetrics;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;

class VarleRecentProblemsWidget extends TableWidget
{
    protected static bool $isDiscovered = false;

    protected static ?int $sort = 4;

    protected int|string|array $columnSpan = 'full';

    protected function getTableHeading(): ?string
    {
        return 'Recent problems (latest Varle export)';
    }

    public function table(Table $table): Table
    {
        $exportId = app(VarleReadinessMetrics::class)->latestVarleExport()?->id ?? 0;

        return $table
            ->query(fn (): Builder => SyncJobItem::query()
                ->where('sync_job_id', $exportId)
                ->with(['product', 'variant'])
                ->latest('id')
                ->limit(50))
            ->columns([
                TextColumn::make('status')
                    ->badge(),
                TextColumn::make('message')
                    ->wrap()
                    ->searchable(),
                TextColumn::make('sku')
                    ->label('SKU')
                    ->placeholder('—'),
                TextColumn::make('product.title')
                    ->label('Product')
                    ->placeholder('—')
                    ->wrap(),
                TextColumn::make('variant.title')
                    ->label('Variant')
                    ->placeholder('—')
                    ->wrap(),
                TextColumn::make('created_at')
                    ->label('Created')
                    ->dateTime()
                    ->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->paginated(false)
            ->emptyStateHeading('No problems recorded')
            ->emptyStateDescription('The latest Varle export has no sync job items.');
    }
}
