<?php

namespace App\Filament\Widgets;

use App\Services\Suppliers\SupplierHealthService;
use Filament\Widgets\Widget;

class SupplierOnboardingHealthWidget extends Widget
{
    protected string $view = 'filament.widgets.supplier-onboarding-health';

    protected int|string|array $columnSpan = 'full';

    protected static ?int $sort = -15;

    /**
     * @return array{
     *     total: int,
     *     enabled: int,
     *     due_now: int,
     *     failed_last_sync: int,
     *     unmatched_total: int,
     *     ambiguous_total: int
     * }
     */
    public function getSnapshot(): array
    {
        return app(SupplierHealthService::class)->snapshot();
    }
}
