<?php

namespace App\Filament\Resources\SourceCategories\Actions;

use App\Enums\VarleExportStatus;
use App\Models\SourceCategory;
use App\Services\Marketplace\CategoryBulkApprovalService;
use App\Services\Marketplace\CategoryBulkMappingService;
use BackedEnum;
use Filament\Actions\BulkAction;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\HtmlString;

class SourceCategoryBulkActions
{
    /**
     * @return array<int, BulkAction>
     */
    public static function make(): array
    {
        return [
            self::exportStatusAction(
                name: 'includeInVarle',
                label: 'Include in Varle',
                icon: Heroicon::OutlinedArrowUpCircle,
                status: VarleExportStatus::Include,
            ),
            self::exportStatusAction(
                name: 'excludeFromVarle',
                label: 'Exclude from Varle',
                icon: Heroicon::OutlinedNoSymbol,
                status: VarleExportStatus::Exclude,
            ),
            self::exportStatusAction(
                name: 'setPendingReview',
                label: 'Set pending review',
                icon: Heroicon::OutlinedClock,
                status: VarleExportStatus::PendingReview,
            ),
            self::exportStatusAction(
                name: 'setAuto',
                label: 'Set to Auto',
                icon: Heroicon::OutlinedSparkles,
                status: VarleExportStatus::Auto,
            ),
            BulkAction::make('mapToVarleCategory')
                ->label('Map to Varle category')
                ->icon(Heroicon::OutlinedArrowsRightLeft)
                ->form([
                    TextInput::make('target_category_path')
                        ->label('Varle target category path')
                        ->placeholder('Apranga > Kelnės')
                        ->required(),
                ])
                ->modalDescription('Creates or updates category mappings for the selected Shopify collections. This does not change include/exclude status.')
                ->action(function (Collection $records, array $data): void {
                    $count = app(CategoryBulkMappingService::class)->applyMapping(
                        $records->pluck('id')->map(fn (mixed $id): int => (int) $id)->all(),
                        (string) $data['target_category_path'],
                    );

                    Notification::make()
                        ->title('Category mappings updated')
                        ->body("{$count} mapping(s) saved.")
                        ->success()
                        ->send();
                }),
            BulkAction::make('applyDefaultExportStatus')
                ->label('Apply category default to products')
                ->icon(Heroicon::OutlinedAdjustmentsHorizontal)
                ->requiresConfirmation()
                ->modalDescription('Updates products using each category\'s default Varle export status. Categories without a default are skipped.')
                ->action(function (Collection $records): void {
                    $service = app(CategoryBulkApprovalService::class);
                    $updatedTotal = 0;
                    $productIds = [];

                    foreach ($records as $category) {
                        if (! $category instanceof SourceCategory || $category->default_varle_export_status === null) {
                            continue;
                        }

                        $result = $service->apply(
                            [(int) $category->id],
                            $category->default_varle_export_status,
                            dispatchReadinessRefresh: false,
                        );

                        $updatedTotal += $result->updatedCount;
                        $productIds = array_values(array_unique([...$productIds, ...$result->productIds]));
                    }

                    if ($productIds !== []) {
                        app(\App\Services\Marketplace\Varle\VarleReadinessRefreshService::class)->dispatch($productIds);
                    }

                    Notification::make()
                        ->title('Category defaults applied')
                        ->body($updatedTotal.' product(s) updated. Readiness refresh started in background.')
                        ->success()
                        ->send();
                }),
        ];
    }

    private static function exportStatusAction(
        string $name,
        string $label,
        string|BackedEnum|null $icon,
        VarleExportStatus $status,
    ): BulkAction {
        return BulkAction::make($name)
            ->label($label)
            ->icon($icon)
            ->requiresConfirmation()
            ->modalHeading($label)
            ->modalDescription(function (Collection $records) use ($status): HtmlString {
                $text = app(CategoryBulkApprovalService::class)->previewDescription(
                    $records->pluck('id')->map(fn (mixed $id): int => (int) $id)->all(),
                    $status,
                );

                return new HtmlString(nl2br(e($text)));
            })
            ->action(function (Collection $records) use ($status, $label): void {
                $result = app(CategoryBulkApprovalService::class)->apply(
                    $records->pluck('id')->map(fn (mixed $id): int => (int) $id)->all(),
                    $status,
                );

                $body = $result->updatedCount.' product(s) updated.';

                if ($result->readinessQueued) {
                    $body .= ' Readiness refresh started in background.';
                }

                Notification::make()
                    ->title($label.' applied')
                    ->body($body)
                    ->success()
                    ->send();
            });
    }
}
