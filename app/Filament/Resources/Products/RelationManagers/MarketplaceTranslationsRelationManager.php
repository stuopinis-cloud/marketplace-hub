<?php

namespace App\Filament\Resources\Products\RelationManagers;

use App\Enums\MarketplaceTranslationStatus;
use App\Jobs\TranslateProductFieldJob;
use App\Models\MarketplaceTranslation;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class MarketplaceTranslationsRelationManager extends RelationManager
{
    protected static string $relationship = 'marketplaceTranslations';

    protected static ?string $title = 'Translations';

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Textarea::make('source_text')->disabled()->columnSpanFull(),
            Textarea::make('translated_text')->rows(5)->columnSpanFull(),
            Select::make('status')
                ->options(collect(MarketplaceTranslationStatus::cases())->mapWithKeys(
                    fn (MarketplaceTranslationStatus $status): array => [$status->value => $status->value],
                )->all())
                ->required(),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('marketplace'),
                TextColumn::make('locale'),
                TextColumn::make('field'),
                TextColumn::make('source_text')->limit(30),
                TextColumn::make('translated_text')->limit(30),
                TextColumn::make('status')->badge(),
                TextColumn::make('provider'),
            ])
            ->recordActions([
                EditAction::make()
                    ->mutateFormDataUsing(function (array $data): array {
                        if (in_array($data['status'] ?? null, [
                            MarketplaceTranslationStatus::Approved->value,
                            MarketplaceTranslationStatus::Reviewed->value,
                        ], true)) {
                            $data['provider'] = 'manual';
                            $data['reviewed_at'] = now();
                        }

                        return $data;
                    }),
                Action::make('approve')
                    ->action(function (MarketplaceTranslation $record): void {
                        $record->update([
                            'status' => MarketplaceTranslationStatus::Approved,
                            'provider' => $record->provider ?: 'manual',
                            'reviewed_at' => now(),
                        ]);
                    }),
                Action::make('regenerate')
                    ->action(function (MarketplaceTranslation $record): void {
                        $record->update(['status' => MarketplaceTranslationStatus::Queued, 'error_message' => null]);
                        TranslateProductFieldJob::dispatch($record->id);
                    }),
            ]);
    }
}
