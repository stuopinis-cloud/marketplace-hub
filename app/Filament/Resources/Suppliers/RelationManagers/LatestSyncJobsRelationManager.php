<?php

namespace App\Filament\Resources\Suppliers\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class LatestSyncJobsRelationManager extends RelationManager
{
    protected static string $relationship = 'syncJobs';

    protected static ?string $title = 'Sync history';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return true;
    }

    public function isReadOnly(): bool
    {
        return true;
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('id')
            ->columns([
                TextColumn::make('id')->label('Job #'),
                TextColumn::make('source')->label('Source'),
                TextColumn::make('status')->badge(),
                TextColumn::make('total_items')->label('Total')->sortable(),
                TextColumn::make('success_items')->label('Success')->sortable(),
                TextColumn::make('failed_items')->label('Failed')->sortable(),
                TextColumn::make('started_at')->dateTime()->sortable(),
                TextColumn::make('finished_at')->dateTime()->sortable(),
                TextColumn::make('error_message')->label('Error')->limit(60)->toggleable(),
            ])
            ->filters([
                SelectFilter::make('status')->options(\App\Enums\SyncJobStatus::class),
            ])
            ->defaultSort('started_at', 'desc')
            ->recordActions([])
            ->toolbarActions([]);
    }
}
