<?php

namespace App\Filament\Resources\Products\Actions;

use App\Models\Product;
use App\Services\Marketplace\Varle\VarleReadinessService;
use Filament\Actions\Action;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\View\View;

class ViewVarleIssuesAction
{
    public static function make(): Action
    {
        return Action::make('viewVarleIssues')
            ->label('View issues')
            ->icon(Heroicon::OutlinedExclamationTriangle)
            ->modalHeading('Varle export issues')
            ->modalContent(function (Product $record): View {
                $analysis = app(VarleReadinessService::class)->analyze($record);
                $record->loadCount('variants');

                return view('filament.products.view-varle-issues', [
                    'record' => $record,
                    'analysis' => $analysis,
                ]);
            })
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('Close');
    }
}
