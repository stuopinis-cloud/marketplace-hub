<?php

namespace App\Filament\Resources\AutomationSchedules\Actions;

use App\Models\AutomationSchedule;
use App\Services\Sync\MarketplaceJobDispatcher;
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
            ->action(function (AutomationSchedule $record, MarketplaceJobDispatcher $dispatcher): void {
                $result = $dispatcher->dispatchDailySync(
                    runShopifyImport: (bool) $record->run_shopify_import,
                    runSupplierSync: (bool) ($record->run_supplier_sync ?? true),
                    runReadinessRefresh: true,
                    runVarleExport: (bool) $record->run_varle_export,
                    generateFailedCsv: (bool) $record->generate_failed_csv,
                );

                if ($result->alreadyRunning) {
                    Notification::make()
                        ->title('Job already running')
                        ->body($result->message ?? 'Daily marketplace sync is already running.')
                        ->warning()
                        ->send();

                    return;
                }

                $record->update([
                    'last_status' => 'queued',
                    'last_error' => null,
                ]);

                Notification::make()
                    ->title('Daily sync queued')
                    ->body(($result->message ?? 'Job started in background.').($result->syncJob ? ' Sync job #'.$result->syncJob->id.'.' : ''))
                    ->success()
                    ->send();
            });
    }
}
