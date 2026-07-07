<?php

namespace App\Filament\Resources\SyncJobs\Actions;

use App\Enums\SyncJobStatus;
use App\Models\SyncJob;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;

class CancelSyncJobAction
{
    public static function make(): Action
    {
        return Action::make('cancelSyncJob')
            ->label('Cancel job')
            ->icon(Heroicon::OutlinedStop)
            ->color('danger')
            ->visible(fn (SyncJob $record): bool => $record->status === SyncJobStatus::Running)
            ->requiresConfirmation()
            ->action(function (SyncJob $record): void {
                $record->update([
                    'cancel_requested_at' => now(),
                ]);

                Notification::make()
                    ->title('Cancellation requested')
                    ->body('The running job will stop at the next safe checkpoint.')
                    ->success()
                    ->send();
            });
    }
}
