<?php

namespace App\Filament\Resources\PiguSupplierXml\Pages;

use App\Filament\Resources\PiguSupplierXml\PiguSupplierXmlResource;
use App\Filament\Resources\PiguSupplierXml\Schemas\PiguSupplierXmlWizardSteps;
use App\Filament\Resources\Suppliers\Concerns\MutatesSupplierFormData;
use App\Models\Supplier;
use App\Services\Marketplace\Pigu\PiguSupplierOfferConfig;
use Filament\Resources\Pages\CreateRecord;
use Filament\Resources\Pages\CreateRecord\Concerns\HasWizard;

class CreatePiguSupplierXml extends CreateRecord
{
    use HasWizard;
    use MutatesSupplierFormData;

    protected static string $resource = PiguSupplierXmlResource::class;

    protected static ?string $title = 'Naujas Pigu tiekėjo XML';

    /** @var array<int, array<string, string|null>> */
    public array $piguPreviewRows = [];

    /**
     * @return array<int, \Filament\Schemas\Components\Wizard\Step>
     */
    public function getSteps(): array
    {
        return PiguSupplierXmlWizardSteps::steps(fn () => $this);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data = $this->mutateSupplierFormData($data, null);

        $data['connector_type'] = Supplier::CONNECTOR_XML_URL;
        $data['enabled'] = (bool) ($data['enabled'] ?? true);
        $data['sync_enabled'] = (bool) ($data['sync_enabled'] ?? true);
        $data['force_daily_sync'] = (bool) ($data['force_daily_sync'] ?? true);
        $data['auth_type'] = $data['auth_type'] ?? Supplier::AUTH_NONE;
        $data['stale_after_minutes'] = $data['stale_after_minutes'] ?? 2880;
        $data['availability_fallback_quantity'] = $data['availability_fallback_quantity'] ?? 5;
        $data['stock_priority'] = $data['stock_priority'] ?? 100;

        $config = is_array($data['config'] ?? null) ? $data['config'] : [];
        $config['match_by_barcode'] = true;
        $config['matching_strategy'] = $config['matching_strategy'] ?? 'sku_global';
        $config[PiguSupplierOfferConfig::CONFIG_KEY] = PiguSupplierOfferConfig::normalize(
            is_array($config[PiguSupplierOfferConfig::CONFIG_KEY] ?? null)
                ? $config[PiguSupplierOfferConfig::CONFIG_KEY]
                : [],
        );
        $data['config'] = $config;

        unset(
            $data['pigu_preview_rows'],
            $data['pigu_available_fields'],
            $data['pigu_item_count'],
        );

        return $data;
    }
}
