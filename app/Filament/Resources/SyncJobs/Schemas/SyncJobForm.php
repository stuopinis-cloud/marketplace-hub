<?php

namespace App\Filament\Resources\SyncJobs\Schemas;

use App\Enums\SyncJobStatus;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class SyncJobForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('type')
                    ->required()
                    ->maxLength(255)
                    ->placeholder('import, export'),
                TextInput::make('source')
                    ->maxLength(255),
                TextInput::make('channel')
                    ->maxLength(255),
                Select::make('status')
                    ->options(SyncJobStatus::class)
                    ->default(SyncJobStatus::Pending)
                    ->required(),
                TextInput::make('total_items')
                    ->numeric()
                    ->default(0),
                TextInput::make('success_items')
                    ->numeric()
                    ->default(0),
                TextInput::make('failed_items')
                    ->numeric()
                    ->default(0),
                DateTimePicker::make('started_at'),
                DateTimePicker::make('finished_at'),
                Textarea::make('error_message')
                    ->columnSpanFull()
                    ->rows(4),
                KeyValue::make('context')
                    ->columnSpanFull(),
            ]);
    }
}
