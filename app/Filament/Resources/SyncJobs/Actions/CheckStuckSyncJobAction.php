<?php

namespace App\Filament\Resources\SyncJobs\Actions;

use App\Models\SyncJob;
use App\Services\Sync\StuckSyncJobMarker;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;

class CheckStuckSyncJobAction
{
    public static function make(): Action
    {
        return Action::make('checkStuckSyncJob')
            ->label('Check stuck now')
            ->icon(Heroicon::OutlinedExclamationTriangle)
            ->visible(fn (SyncJob $record): bool => $record->status?->value === 'running')
            ->action(function (SyncJob $record, StuckSyncJobMarker $marker): void {
                if ($marker->markIfStuck($record)) {
                    Notification::make()
                        ->title('Stuck sync job marked failed')
                        ->body("Sync job #{$record->id} was marked failed.")
                        ->warning()
                        ->send();

                    return;
                }

                Notification::make()
                    ->title('Job is not stuck')
                    ->body('This running sync job has a recent heartbeat.')
                    ->success()
                    ->send();
            });
    }
}
