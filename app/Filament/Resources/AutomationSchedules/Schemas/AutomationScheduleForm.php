<?php

namespace App\Filament\Resources\AutomationSchedules\Schemas;

use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\TimePicker;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class AutomationScheduleForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->required()
                    ->maxLength(255),
                Select::make('type')
                    ->options([
                        'daily_marketplace_sync' => 'Daily marketplace sync',
                    ])
                    ->required()
                    ->default('daily_marketplace_sync'),
                Toggle::make('enabled')
                    ->default(false)
                    ->helperText('Disabled schedules are never run automatically.'),
                Select::make('frequency')
                    ->options([
                        'daily' => 'Daily',
                    ])
                    ->required()
                    ->default('daily'),
                TimePicker::make('run_time')
                    ->label('Run time')
                    ->seconds(false)
                    ->required()
                    ->default('03:30')
                    ->helperText('Local time in the selected timezone when the sync should run.'),
                Select::make('timezone')
                    ->options(self::timezoneOptions())
                    ->searchable()
                    ->required()
                    ->default('Europe/Vilnius'),
                Toggle::make('run_shopify_import')
                    ->label('Run Shopify import')
                    ->default(true),
                Toggle::make('run_supplier_sync')
                    ->label('Run enabled supplier syncs')
                    ->default(false),
                Toggle::make('run_varle_export')
                    ->label('Run Varle export')
                    ->default(true),
                Toggle::make('generate_failed_csv')
                    ->label('Generate failed CSV')
                    ->default(true),
                DateTimePicker::make('last_run_at')
                    ->disabled()
                    ->dehydrated(false),
                DateTimePicker::make('next_run_at')
                    ->disabled()
                    ->dehydrated(false),
                TextInput::make('last_status')
                    ->disabled()
                    ->dehydrated(false),
                Textarea::make('last_error')
                    ->disabled()
                    ->dehydrated(false)
                    ->columnSpanFull(),
                KeyValue::make('config')
                    ->columnSpanFull(),
            ]);
    }

    /**
     * @return array<string, string>
     */
    private static function timezoneOptions(): array
    {
        return [
            'Europe/Vilnius' => 'Europe/Vilnius',
            'Europe/Riga' => 'Europe/Riga',
            'Europe/Warsaw' => 'Europe/Warsaw',
            'Europe/Berlin' => 'Europe/Berlin',
            'UTC' => 'UTC',
        ];
    }
}
