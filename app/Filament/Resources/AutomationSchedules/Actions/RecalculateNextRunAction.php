<?php

namespace App\Filament\Resources\AutomationSchedules\Actions;

use App\Models\AutomationSchedule;
use App\Services\Automation\AutomationScheduleRunner;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;

class RecalculateNextRunAction
{
    public static function make(): Action
    {
        return Action::make('recalculateNextRun')
            ->label('Recalculate next run')
            ->icon(Heroicon::OutlinedArrowPath)
            ->requiresConfirmation()
            ->action(function (AutomationSchedule $record, AutomationScheduleRunner $runner): void {
                $runner->refreshNextRunAt($record);
                $record->refresh();

                Notification::make()
                    ->title('Next run recalculated')
                    ->body('Next run at: '.($record->next_run_at?->toDateTimeString() ?? 'not set'))
                    ->success()
                    ->send();
            });
    }
}
