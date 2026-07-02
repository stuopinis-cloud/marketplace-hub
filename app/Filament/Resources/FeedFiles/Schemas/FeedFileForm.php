<?php

namespace App\Filament\Resources\FeedFiles\Schemas;

use App\Enums\FeedFileStatus;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class FeedFileForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('marketplace_channel_id')
                    ->relationship('marketplaceChannel', 'name')
                    ->required()
                    ->searchable()
                    ->preload(),
                TextInput::make('filename')
                    ->required()
                    ->maxLength(255),
                TextInput::make('path')
                    ->required()
                    ->maxLength(255),
                TextInput::make('public_url')
                    ->url()
                    ->columnSpanFull(),
                Select::make('status')
                    ->options(FeedFileStatus::class)
                    ->default(FeedFileStatus::Generated)
                    ->required(),
                DateTimePicker::make('generated_at'),
            ]);
    }
}
