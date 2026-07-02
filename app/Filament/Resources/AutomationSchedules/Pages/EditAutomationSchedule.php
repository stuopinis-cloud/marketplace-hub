<?php

namespace App\Filament\Resources\AutomationSchedules\Pages;

use App\Filament\Resources\AutomationSchedules\Actions\RecalculateNextRunAction;
use App\Filament\Resources\AutomationSchedules\Actions\RunNowAction;
use App\Filament\Resources\AutomationSchedules\AutomationScheduleResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditAutomationSchedule extends EditRecord
{
    protected static string $resource = AutomationScheduleResource::class;

    protected function getHeaderActions(): array
    {
        return [
            RunNowAction::make(),
            RecalculateNextRunAction::make(),
            DeleteAction::make(),
        ];
    }
}
