<?php

namespace App\Filament\Resources\AutomationSchedules;

use App\Filament\Resources\AutomationSchedules\Pages\CreateAutomationSchedule;
use App\Filament\Resources\AutomationSchedules\Pages\EditAutomationSchedule;
use App\Filament\Resources\AutomationSchedules\Pages\ListAutomationSchedules;
use App\Filament\Resources\AutomationSchedules\Schemas\AutomationScheduleForm;
use App\Filament\Resources\AutomationSchedules\Tables\AutomationSchedulesTable;
use App\Models\AutomationSchedule;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class AutomationScheduleResource extends Resource
{
    protected static ?string $model = AutomationSchedule::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClock;

    protected static string|UnitEnum|null $navigationGroup = 'Automation';

    protected static ?int $navigationSort = 1;

    protected static ?string $navigationLabel = 'Schedules';

    protected static ?string $modelLabel = 'Schedule';

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return AutomationScheduleForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return AutomationSchedulesTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAutomationSchedules::route('/'),
            'create' => CreateAutomationSchedule::route('/create'),
            'edit' => EditAutomationSchedule::route('/{record}/edit'),
        ];
    }
}
