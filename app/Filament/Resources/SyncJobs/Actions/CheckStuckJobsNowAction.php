<?php

namespace App\Filament\Resources\SyncJobs\Actions;

use App\Services\Sync\StuckSyncJobMarker;
use Filament\Actions\Action;
use Filament\Notifications\Notification;

class CheckStuckJobsNowAction
{
    public static function make(): Action
    {
        return Action::make('checkStuckJobsNow')
            ->label('Check stuck jobs now')
            ->icon('heroicon-o-exclamation-triangle')
            ->action(function (StuckSyncJobMarker $marker): void {
                $marked = $marker->markStuckJobs();

                if ($marked === 0) {
                    Notification::make()
                        ->title('No stuck jobs found')
                        ->body('All running sync jobs have recent heartbeats.')
                        ->success()
                        ->send();

                    return;
                }

                Notification::make()
                    ->title('Stuck sync jobs marked failed')
                    ->body($marked === 1
                        ? '1 stuck sync job was marked failed.'
                        : "{$marked} stuck sync jobs were marked failed.")
                    ->warning()
                    ->send();
            });
    }
}
