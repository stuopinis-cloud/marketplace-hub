<?php

namespace App\Filament\Resources\AutomationSchedules\Tables;

use App\Filament\Resources\AutomationSchedules\Actions\RecalculateNextRunAction;
use App\Filament\Resources\AutomationSchedules\Actions\RunNowAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class AutomationSchedulesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('type')
                    ->badge()
                    ->sortable(),
                IconColumn::make('enabled')
                    ->boolean(),
                TextColumn::make('run_time')
                    ->label('Run time')
                    ->formatStateUsing(fn (?string $state): string => $state ? substr($state, 0, 5) : '—'),
                TextColumn::make('timezone')
                    ->toggleable(),
                TextColumn::make('next_run_at')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('last_run_at')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('last_status')
                    ->badge()
                    ->sortable(),
            ])
            ->filters([
                TernaryFilter::make('enabled'),
            ])
            ->recordActions([
                RunNowAction::make(),
                RecalculateNextRunAction::make(),
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('name');
    }
}
