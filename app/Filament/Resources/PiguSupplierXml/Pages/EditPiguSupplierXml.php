<?php

namespace App\Filament\Resources\PiguSupplierXml\Pages;

use App\Filament\Resources\PiguSupplierXml\PiguSupplierXmlResource;
use App\Filament\Resources\PiguSupplierXml\Schemas\PiguSupplierXmlWizardSteps;
use App\Filament\Resources\Suppliers\Concerns\MutatesSupplierFormData;
use App\Models\Supplier;
use App\Services\Marketplace\Pigu\PiguSupplierOfferConfig;
use App\Services\Sync\MarketplaceJobDispatcher;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Resources\Pages\EditRecord\Concerns\HasWizard;
use Filament\Support\Icons\Heroicon;

class EditPiguSupplierXml extends EditRecord
{
    use HasWizard;
    use MutatesSupplierFormData;

    protected static string $resource = PiguSupplierXmlResource::class;

    protected static ?string $title = 'Redaguoti Pigu tiekėjo XML';

    /** @var array<int, array<string, string|null>> */
    public array $piguPreviewRows = [];

    /**
     * @return array<int, \Filament\Schemas\Components\Wizard\Step>
     */
    public function getSteps(): array
    {
        return PiguSupplierXmlWizardSteps::steps(fn () => $this);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('syncNow')
                ->label('Sinchronizuoti dabar')
                ->icon(Heroicon::OutlinedArrowPath)
                ->requiresConfirmation()
                ->action(function (MarketplaceJobDispatcher $dispatcher): void {
                    /** @var Supplier $record */
                    $record = $this->getRecord();
                    $result = $dispatcher->dispatchSupplierSync($record->code);
                    Notification::make()
                        ->title($result->alreadyRunning ? 'Sinchronizacija jau vyksta' : 'Sinchronizacija įtraukta į eilę')
                        ->body($result->message)
                        ->{$result->alreadyRunning ? 'warning' : 'success'}()
                        ->send();
                }),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        $credentials = $data['credentials'] ?? [];
        if (is_array($credentials)) {
            $data['credential_username'] = $credentials['username'] ?? null;
            $data['credential_password'] = filled($credentials['password'] ?? null) ? '********' : null;
            $data['credential_token'] = filled($credentials['token'] ?? null) ? '********' : null;
        }

        $config = is_array($data['config'] ?? null) ? $data['config'] : [];
        $config[PiguSupplierOfferConfig::CONFIG_KEY] = array_merge(
            PiguSupplierOfferConfig::defaults(),
            is_array($config[PiguSupplierOfferConfig::CONFIG_KEY] ?? null)
                ? $config[PiguSupplierOfferConfig::CONFIG_KEY]
                : [],
        );
        $data['config'] = $config;

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        /** @var Supplier $record */
        $record = $this->getRecord();
        $data = $this->mutateSupplierFormData($data, $record);

        $data['connector_type'] = Supplier::CONNECTOR_XML_URL;

        $config = is_array($data['config'] ?? null) ? $data['config'] : [];
        $config['match_by_barcode'] = true;
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
