<?php

namespace App\Filament\Resources\SyncJobs\Schemas;

use App\Enums\SyncJobStatus;
use App\Models\SyncJob;
use App\Services\Sync\SyncJobHealthService;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Placeholder;
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
                TextInput::make('process_id')
                    ->label('Process ID')
                    ->disabled()
                    ->dehydrated(false),
                DateTimePicker::make('started_at'),
                DateTimePicker::make('finished_at'),
                DateTimePicker::make('heartbeat_at')
                    ->disabled()
                    ->dehydrated(false),
                DateTimePicker::make('cancel_requested_at')
                    ->disabled()
                    ->dehydrated(false),
                DateTimePicker::make('cancelled_at')
                    ->disabled()
                    ->dehydrated(false),
                Placeholder::make('health_status')
                    ->label('Health status')
                    ->content(function (?SyncJob $record): string {
                        if ($record === null) {
                            return '—';
                        }

                        $health = app(SyncJobHealthService::class)->assess($record);

                        return $health['label'].' ('.$health['health_status'].')';
                    }),
                Placeholder::make('health_message')
                    ->label('Health message')
                    ->content(function (?SyncJob $record): string {
                        if ($record === null) {
                            return '—';
                        }

                        return app(SyncJobHealthService::class)->assess($record)['human_message'];
                    }),
                Placeholder::make('progress_label')
                    ->label('Progress')
                    ->content(function (?SyncJob $record): string {
                        if ($record === null) {
                            return '—';
                        }

                        return app(SyncJobHealthService::class)->assess($record)['progress_label'];
                    }),
                Placeholder::make('current_product_handle')
                    ->label('Current product handle')
                    ->content(fn (?SyncJob $record): string => (string) data_get($record?->context, 'current_product_handle', '—')),
                Placeholder::make('current_stage')
                    ->label('Current stage')
                    ->content(fn (?SyncJob $record): string => (string) data_get($record?->context, 'stage', '—')),
                Placeholder::make('current_product_index')
                    ->label('Current product index')
                    ->content(fn (?SyncJob $record): string => (string) data_get($record?->context, 'current_product_index', '—')),
                Textarea::make('error_message')
                    ->columnSpanFull()
                    ->rows(4),
                KeyValue::make('context')
                    ->columnSpanFull(),
            ]);
    }
}
