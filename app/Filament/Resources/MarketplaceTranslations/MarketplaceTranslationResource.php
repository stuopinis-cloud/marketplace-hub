<?php

namespace App\Filament\Resources\MarketplaceTranslations;

use App\Enums\MarketplaceTranslationStatus;
use App\Filament\Resources\MarketplaceTranslations\Pages\ListMarketplaceTranslations;
use App\Jobs\TranslateProductFieldJob;
use App\Models\MarketplaceTranslation;
use App\Services\Marketplace\Translations\TranslationQueueService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Collection;
use UnitEnum;

class MarketplaceTranslationResource extends Resource
{
    protected static ?string $model = MarketplaceTranslation::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedLanguage;

    protected static string|UnitEnum|null $navigationGroup = 'Marketplace';

    protected static ?int $navigationSort = 25;

    protected static ?string $navigationLabel = 'Marketplace Translations';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('marketplace')->disabled(),
            TextInput::make('locale')->disabled(),
            TextInput::make('field')->disabled(),
            Textarea::make('source_text')->disabled()->columnSpanFull(),
            Textarea::make('translated_text')->rows(6)->columnSpanFull(),
            Select::make('status')
                ->options(collect(MarketplaceTranslationStatus::cases())->mapWithKeys(
                    fn (MarketplaceTranslationStatus $status): array => [$status->value => $status->value],
                )->all())
                ->required(),
            TextInput::make('provider')->disabled(),
            Textarea::make('error_message')->disabled()->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('marketplace')->sortable()->searchable(),
                TextColumn::make('locale')->sortable(),
                TextColumn::make('field')->sortable()->searchable(),
                TextColumn::make('translatable_type')->label('Entity')->toggleable(),
                TextColumn::make('translatable_id')->label('Entity ID')->toggleable(),
                TextColumn::make('source_text')->limit(40)->wrap()->searchable(),
                TextColumn::make('translated_text')->limit(40)->wrap()->searchable(),
                TextColumn::make('status')->badge()->sortable(),
                TextColumn::make('provider')->toggleable(),
                TextColumn::make('translated_at')->dateTime()->sortable()->toggleable(),
                TextColumn::make('reviewed_at')->dateTime()->sortable()->toggleable(),
                TextColumn::make('error_message')->limit(40)->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('marketplace')
                    ->options(['ebay' => 'eBay', 'varle' => 'Varle']),
                SelectFilter::make('locale')
                    ->options(['en' => 'en', 'lt' => 'lt']),
                SelectFilter::make('status')
                    ->options(collect(MarketplaceTranslationStatus::cases())->mapWithKeys(
                        fn (MarketplaceTranslationStatus $status): array => [$status->value => $status->value],
                    )->all()),
                SelectFilter::make('field')
                    ->options([
                        MarketplaceTranslation::FIELD_TITLE => 'title',
                        MarketplaceTranslation::FIELD_DESCRIPTION => 'description',
                        MarketplaceTranslation::FIELD_OPTION_NAME => 'option_name',
                        MarketplaceTranslation::FIELD_OPTION_VALUE => 'option_value',
                        MarketplaceTranslation::FIELD_ATTRIBUTE_NAME => 'attribute_name',
                        MarketplaceTranslation::FIELD_ATTRIBUTE_VALUE => 'attribute_value',
                    ]),
            ])
            ->recordActions([
                EditAction::make()
                    ->mutateFormDataUsing(function (array $data): array {
                        if (($data['status'] ?? null) === MarketplaceTranslationStatus::Approved->value
                            || ($data['status'] ?? null) === MarketplaceTranslationStatus::Reviewed->value) {
                            $data['provider'] = $data['provider'] ?? 'manual';
                            $data['reviewed_at'] = now();
                        }

                        return $data;
                    }),
                Action::make('approve')
                    ->label('Approve')
                    ->action(function (MarketplaceTranslation $record): void {
                        $record->update([
                            'status' => MarketplaceTranslationStatus::Approved,
                            'provider' => $record->provider ?: 'manual',
                            'reviewed_at' => now(),
                            'error_message' => null,
                        ]);
                    }),
                Action::make('markReviewed')
                    ->label('Mark reviewed')
                    ->action(function (MarketplaceTranslation $record): void {
                        $record->update([
                            'status' => MarketplaceTranslationStatus::Reviewed,
                            'reviewed_at' => now(),
                        ]);
                    }),
                Action::make('regenerate')
                    ->label('Regenerate')
                    ->requiresConfirmation()
                    ->action(function (MarketplaceTranslation $record): void {
                        $record->update([
                            'status' => MarketplaceTranslationStatus::Queued,
                            'error_message' => null,
                        ]);
                        TranslateProductFieldJob::dispatch($record->id);
                    }),
            ])
            ->toolbarActions([
                BulkAction::make('approveSelected')
                    ->label('Approve selected')
                    ->action(function (Collection $records): void {
                        $records->each(fn (MarketplaceTranslation $record) => $record->update([
                            'status' => MarketplaceTranslationStatus::Approved,
                            'provider' => $record->provider ?: 'manual',
                            'reviewed_at' => now(),
                            'error_message' => null,
                        ]));
                    }),
                BulkAction::make('translateSelected')
                    ->label('Translate selected')
                    ->action(function (Collection $records): void {
                        app(TranslationQueueService::class)->queueTranslationIds(
                            $records->pluck('id')->map(fn ($id): int => (int) $id)->all(),
                        );
                        Notification::make()->title('Translation jobs queued')->success()->send();
                    }),
                BulkAction::make('regenerateSelected')
                    ->label('Regenerate selected')
                    ->action(function (Collection $records): void {
                        foreach ($records as $record) {
                            $record->update([
                                'status' => MarketplaceTranslationStatus::Queued,
                                'error_message' => null,
                            ]);
                            TranslateProductFieldJob::dispatch($record->id);
                        }
                    }),
            ])
            ->headerActions([
                Action::make('queueMissing')
                    ->label('Queue missing translations')
                    ->action(function (): void {
                        $result = app(TranslationQueueService::class)->queueMissingForMarketplace('ebay', 'en');
                        Notification::make()
                            ->title('Missing translations queued')
                            ->body(sprintf(
                                '%d product jobs, %d field jobs.',
                                $result['products_queued'],
                                $result['fields_queued'],
                            ))
                            ->success()
                            ->send();
                    }),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListMarketplaceTranslations::route('/'),
        ];
    }
}
