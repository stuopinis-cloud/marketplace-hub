<?php

namespace App\Filament\Resources\CategoryMappings\Actions;

use App\Models\MarketplaceChannel;
use App\Services\Marketplace\CategoryMappingCsvImportOptions;
use App\Services\Marketplace\CategoryMappingCsvImporter;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\View;
use Filament\Schemas\Components\Wizard\Step;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\HtmlString;

class ImportCategoryMappingsCsvAction
{
    public static function make(): Action
    {
        return Action::make('importCategoryMappingsCsv')
            ->label('Import CSV')
            ->icon(Heroicon::OutlinedArrowUpTray)
            ->modalHeading('Import category mappings from CSV')
            ->modalDescription('Upload a CSV with columns shopify_collection, shopify_handle, and varle_final_category.')
            ->modalWidth('5xl')
            ->steps([
                Step::make('upload')
                    ->label('Upload')
                    ->description('Choose the CSV file and import options.')
                    ->schema([
                        FileUpload::make('csv_file')
                            ->label('CSV file')
                            ->acceptedFileTypes([
                                'text/csv',
                                'text/plain',
                                'application/csv',
                                'application/vnd.ms-excel',
                            ])
                            ->disk('local')
                            ->directory('imports/category-mappings')
                            ->required()
                            ->maxFiles(1),
                        Select::make('marketplace_channel_id')
                            ->label('Marketplace channel')
                            ->options(fn (): array => MarketplaceChannel::query()->orderBy('name')->pluck('name', 'id')->all())
                            ->default(fn (): ?int => MarketplaceChannel::query()->where('type', 'varle')->value('id'))
                            ->searchable()
                            ->required(),
                        Select::make('source_type')
                            ->options([
                                'collection' => 'Collection',
                                'product_type' => 'Product type',
                                'tag' => 'Tag',
                            ])
                            ->default('collection')
                            ->required(),
                        TextInput::make('priority')
                            ->numeric()
                            ->default(100)
                            ->required(),
                        Toggle::make('enabled')
                            ->default(true),
                        Toggle::make('export_enabled')
                            ->label('Export enabled')
                            ->default(true),
                        Toggle::make('dry_run')
                            ->label('Dry run')
                            ->helperText('Validate and preview without writing to the database.')
                            ->default(false),
                    ]),
                Step::make('preview')
                    ->label('Preview')
                    ->description('Review the first 20 rows before importing.')
                    ->schema(fn (Get $get): array => [
                        View::make('filament.resources.category-mappings.import-preview')
                            ->viewData(function () use ($get): array {
                                return self::buildPreviewViewData($get);
                            }),
                    ]),
            ])
            ->action(function (array $data, CategoryMappingCsvImporter $importer): void {
                $csvPath = self::resolveUploadedCsvPath($data['csv_file'] ?? null);

                if ($csvPath === null) {
                    Notification::make()
                        ->title('CSV file is required')
                        ->danger()
                        ->send();

                    return;
                }

                $options = CategoryMappingCsvImportOptions::fromArray($data);
                $result = $importer->import(Storage::disk('local')->path($csvPath), $options);

                $notification = Notification::make()
                    ->title($options->dryRun ? 'Dry run complete' : 'Category mappings imported')
                    ->body($result->summaryMessage())
                    ->success();

                if ($result->failedCsvRelativePath !== null) {
                    $downloadUrl = route('exports.category-mapping-import-failed', [
                        'filename' => basename($result->failedCsvRelativePath),
                    ]);

                    $notification->body(new HtmlString(
                        e($result->summaryMessage())
                        .'<br><a class="underline font-medium" href="'.e($downloadUrl).'">Download failed rows CSV</a>'
                    ));
                }

                $notification->send();
            });
    }

    /**
     * @return array<string, mixed>
     */
    private static function buildPreviewViewData(Get $get): array
    {
        try {
            $csvPath = self::resolveUploadedCsvPath($get('csv_file'));

            if ($csvPath === null || blank($get('marketplace_channel_id'))) {
                return [
                    'error' => 'Upload a CSV file and choose a marketplace channel to preview rows.',
                    'rows' => [],
                    'summary' => null,
                ];
            }

            $options = CategoryMappingCsvImportOptions::fromArray([
                'marketplace_channel_id' => $get('marketplace_channel_id'),
                'source_type' => $get('source_type'),
                'priority' => $get('priority'),
                'enabled' => $get('enabled'),
                'export_enabled' => $get('export_enabled'),
                'dry_run' => true,
            ]);

            $result = app(CategoryMappingCsvImporter::class)->previewFromStoragePath($csvPath, $options);

            return [
                'error' => null,
                'rows' => $result->previewRows,
                'summary' => $result,
            ];
        } catch (\Throwable $exception) {
            return [
                'error' => $exception->getMessage(),
                'rows' => [],
                'summary' => null,
            ];
        }
    }

    /**
     * @param  mixed  $value
     */
    private static function resolveUploadedCsvPath(mixed $value): ?string
    {
        if (is_string($value) && $value !== '') {
            return $value;
        }

        if (is_array($value)) {
            $first = reset($value);

            return is_string($first) && $first !== '' ? $first : null;
        }

        return null;
    }
}
