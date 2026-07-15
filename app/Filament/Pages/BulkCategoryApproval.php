<?php

namespace App\Filament\Pages;

use App\Enums\VarleExportStatus;
use App\Filament\Resources\SourceCategories\Actions\SourceCategoryBulkActions;
use App\Filament\Resources\SourceCategories\Tables\SourceCategoriesTable;
use App\Models\SourceCategory;
use App\Services\Marketplace\CategoryBulkApprovalService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
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
            ->query(SourceCategory::query())
            ->toolbarActions([
                BulkActionGroup::make(SourceCategoryBulkActions::make()),
            ]);
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
            Action::make('includeSelectedCategories')
                ->label('Include in Varle')
                ->icon(Heroicon::OutlinedArrowUpCircle)
                ->requiresConfirmation()
                ->modalDescription(fn (): HtmlString => $this->previewHtml(VarleExportStatus::Include))
                ->action(fn () => $this->applyToSelected(VarleExportStatus::Include, 'Include in Varle')),
            Action::make('excludeSelectedCategories')
                ->label('Exclude from Varle')
                ->icon(Heroicon::OutlinedNoSymbol)
                ->requiresConfirmation()
                ->modalDescription(fn (): HtmlString => $this->previewHtml(VarleExportStatus::Exclude))
                ->action(fn () => $this->applyToSelected(VarleExportStatus::Exclude, 'Exclude from Varle')),
            Action::make('pendingSelectedCategories')
                ->label('Set pending review')
                ->icon(Heroicon::OutlinedClock)
                ->requiresConfirmation()
                ->modalDescription(fn (): HtmlString => $this->previewHtml(VarleExportStatus::PendingReview))
                ->action(fn () => $this->applyToSelected(VarleExportStatus::PendingReview, 'Pending review')),
            Action::make('autoSelectedCategories')
                ->label('Set to Auto')
                ->icon(Heroicon::OutlinedSparkles)
                ->requiresConfirmation()
                ->modalDescription(fn (): HtmlString => $this->previewHtml(VarleExportStatus::Auto))
                ->action(fn () => $this->applyToSelected(VarleExportStatus::Auto, 'Auto')),
        ];
    }

    private function applyToSelected(VarleExportStatus $status, string $label): void
    {
        $records = $this->getSelectedTableRecords();

        if ($records->isEmpty()) {
            Notification::make()
                ->title('No categories selected')
                ->body('Select one or more categories in the table below first.')
                ->warning()
                ->send();

            return;
        }

        $result = app(CategoryBulkApprovalService::class)->apply(
            $records->pluck('id')->map(fn (mixed $id): int => (int) $id)->all(),
            $status,
        );

        Notification::make()
            ->title($label.' applied')
            ->body($result->updatedCount.' product(s) updated. Readiness refresh started in background.')
            ->success()
            ->send();
    }

    private function previewHtml(VarleExportStatus $status): HtmlString
    {
        $records = $this->getSelectedTableRecords();

        if ($records->isEmpty()) {
            return new HtmlString('Select one or more categories in the table below to preview affected products.');
        }

        return new HtmlString(nl2br(e(app(CategoryBulkApprovalService::class)->previewDescription(
            $records->pluck('id')->map(fn (mixed $id): int => (int) $id)->all(),
            $status,
        ))));
    }
}
