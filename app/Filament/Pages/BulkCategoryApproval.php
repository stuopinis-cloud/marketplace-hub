<?php

namespace App\Filament\Pages;

use App\Enums\VarleExportStatus;
use App\Filament\Resources\SourceCategories\Tables\SourceCategoriesTable;
use App\Models\SourceCategory;
use App\Services\Marketplace\CategoryBulkApprovalService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Support\HtmlString;
use UnitEnum;

class BulkCategoryApproval extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSquaresPlus;

    protected static ?string $navigationLabel = 'Bulk Category Approval';

    protected static ?string $title = 'Bulk Category Approval';

    protected static string|UnitEnum|null $navigationGroup = 'Marketplaces';

    protected static ?int $navigationSort = 2;

    protected string $view = 'filament.pages.bulk-category-approval';

    public function table(Table $table): Table
    {
        return SourceCategoriesTable::configure($table)
            ->query(SourceCategory::query());
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('applyByCategoryPicker')
                ->label('Apply by category picker')
                ->icon(Heroicon::OutlinedAdjustmentsHorizontal)
                ->form([
                    Select::make('category_ids')
                        ->label('Shopify categories / collections')
                        ->multiple()
                        ->searchable()
                        ->options(fn (): array => SourceCategory::query()->orderBy('name')->pluck('name', 'id')->all())
                        ->required(),
                    Select::make('target_status')
                        ->label('Varle export status')
                        ->options(collect(VarleExportStatus::cases())->mapWithKeys(
                            fn (VarleExportStatus $status): array => [$status->value => $status->label()],
                        )->all())
                        ->required(),
                ])
                ->modalDescription(function (array $data): HtmlString {
                    $status = VarleExportStatus::from((string) ($data['target_status'] ?? VarleExportStatus::Include->value));

                    return new HtmlString(nl2br(e(app(CategoryBulkApprovalService::class)->previewDescription(
                        array_map('intval', $data['category_ids'] ?? []),
                        $status,
                    ))));
                })
                ->action(function (array $data): void {
                    $status = VarleExportStatus::from((string) $data['target_status']);
                    $result = app(CategoryBulkApprovalService::class)->apply(
                        array_map('intval', $data['category_ids'] ?? []),
                        $status,
                    );

                    Notification::make()
                        ->title($status->label().' applied')
                        ->body($result->updatedCount.' product(s) updated. Readiness refresh started in background.')
                        ->success()
                        ->send();
                }),
        ];
    }
}
