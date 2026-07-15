<?php

namespace App\Filament\Resources\SourceCategories\Schemas;

use App\Enums\VarleExportStatus;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class SourceCategoryForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->disabled(),
            TextInput::make('handle')->disabled(),
            TextInput::make('type')->label('Source type')->disabled(),
            Select::make('default_varle_export_status')
                ->label('Default Varle export status')
                ->helperText('Bulk helper only. Does not override explicit product status at runtime.')
                ->options(VarleExportStatus::class)
                ->nullable(),
        ]);
    }
}
