<?php

namespace App\Filament\Resources\AutomationSchedules\Actions;

use App\Models\AutomationSchedule;
use App\Services\Automation\AutomationScheduleRunner;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;

class RunNowAction
{
    public static function make(): Action
    {
        return Action::make('runNow')
            ->label('Run now')
            ->icon(Heroicon::OutlinedPlay)
            ->requiresConfirmation()
            ->action(function (AutomationSchedule $record, AutomationScheduleRunner $runner): void {
                $result = $runner->runSchedule($record);
                $record->refresh();

                if ($result->status === 'success') {
                    Notification::make()
                        ->title('Schedule ran successfully')
                        ->body($result->message ?? 'Daily marketplace sync completed.')
                        ->success()
                        ->send();

                    return;
                }

                if ($result->status === 'failed') {
                    Notification::make()
                        ->title('Schedule run failed')
                        ->body($result->message ?? 'The schedule run failed.')
                        ->danger()
                        ->send();

                    return;
                }

                Notification::make()
                    ->title('Schedule was not run')
                    ->body($result->message ?? 'The schedule was '.$result->status.'.')
                    ->warning()
                    ->send();
            });
    }
}
