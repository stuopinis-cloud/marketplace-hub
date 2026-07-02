<?php

namespace App\Filament\Resources\Sources\Schemas;

use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class SourceForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->required()
                    ->maxLength(255),
                TextInput::make('type')
                    ->required()
                    ->maxLength(255)
                    ->placeholder('shopify, csv, xml'),
                Toggle::make('enabled')
                    ->default(true),
                KeyValue::make('config')
                    ->columnSpanFull(),
            ]);
    }
}
