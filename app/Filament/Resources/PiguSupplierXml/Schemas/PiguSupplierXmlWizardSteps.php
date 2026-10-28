<?php

namespace App\Filament\Resources\PiguSupplierXml\Schemas;

use App\Models\Supplier;
use App\Services\Marketplace\Pigu\PiguSupplierOfferStats;
use App\Services\Marketplace\Pigu\PiguSupplierXmlFieldDetector;
use App\Services\Suppliers\SupplierFormDataFactory;
use App\Services\Suppliers\Xml\SupplierXmlFeedClient;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Components\Wizard\Step;
use Filament\Actions\Action;
use Illuminate\Support\HtmlString;
use Throwable;

class PiguSupplierXmlWizardSteps
{
    /**
     * @return array<int, Step>
     */
    public static function steps(?callable $getLivewire = null): array
    {
        return [
            self::supplierStep(),
            self::mappingStep($getLivewire),
            self::pricingStep(),
            self::stockStep(),
            self::reviewStep($getLivewire),
        ];
    }

    private static function supplierStep(): Step
    {
        return Step::make('Tiekėjas')
            ->description('Pavadinimas, XML adresas ir prisijungimas')
            ->schema([
                TextInput::make('name')
                    ->label('Tiekėjo pavadinimas')
                    ->required()
                    ->maxLength(255)
                    ->live(onBlur: true)
                    ->afterStateUpdated(function (Set $set, Get $get, ?string $state): void {
                        if (blank($get('code')) && filled($state)) {
                            $set('code', SupplierFormDataFactory::codeFromName($state));
                        }
                    }),
                TextInput::make('code')
                    ->label('Kodas (sisteminis)')
                    ->required()
                    ->maxLength(64)
                    ->helperText('Naudojamas automatinėms sinchronizacijoms. Paprastai užpildomas automatiškai.'),
                TextInput::make('endpoint_url')
                    ->label('XML adresas (URL)')
                    ->url()
                    ->required()
                    ->columnSpanFull(),
                Select::make('auth_type')
                    ->label('Autentifikacija (neprivaloma)')
                    ->options([
                        Supplier::AUTH_NONE => 'Nėra',
                        Supplier::AUTH_BASIC => 'Basic (vartotojas / slaptažodis)',
                        Supplier::AUTH_BEARER_TOKEN => 'Bearer token',
                    ])
                    ->default(Supplier::AUTH_NONE)
                    ->live(),
                TextInput::make('credential_username')
                    ->label('Vartotojo vardas')
                    ->visible(fn (Get $get): bool => $get('auth_type') === Supplier::AUTH_BASIC),
                TextInput::make('credential_password')
                    ->label('Slaptažodis')
                    ->password()
                    ->revealable()
                    ->visible(fn (Get $get): bool => in_array($get('auth_type'), [
                        Supplier::AUTH_BASIC,
                        Supplier::AUTH_BEARER_TOKEN,
                    ], true)),
                TextInput::make('credential_token')
                    ->label('Token')
                    ->password()
                    ->revealable()
                    ->visible(fn (Get $get): bool => $get('auth_type') === Supplier::AUTH_BEARER_TOKEN),
                Toggle::make('enabled')
                    ->label('Įjungtas')
                    ->default(true)
                    ->helperText('Išjungtas tiekėjas nebus sinchronizuojamas ir neeksportuos pasiūlymų.'),
                Actions::make([
                    Action::make('testXmlConnection')
                        ->label('Tikrinti XML ryšį')
                        ->action(function (Get $get): void {
                            $url = trim((string) $get('endpoint_url'));
                            if ($url === '') {
                                Notification::make()->title('Įveskite XML adresą')->danger()->send();

                                return;
                            }

                            try {
                                $ok = app(SupplierXmlFeedClient::class)->testConnection($url);
                                if ($ok) {
                                    Notification::make()->title('XML ryšys sėkmingas')->success()->send();
                                } else {
                                    Notification::make()->title('Nepavyko pasiekti XML')->danger()->send();
                                }
                            } catch (Throwable $exception) {
                                Notification::make()
                                    ->title('XML ryšio klaida')
                                    ->body($exception->getMessage())
                                    ->danger()
                                    ->send();
                            }
                        }),
                ]),
            ]);
    }

    private static function mappingStep(?callable $getLivewire): Step
    {
        return Step::make('Laukų susiejimas')
            ->description('SKU, EAN, likutis, kaina')
            ->schema([
                Section::make('Automatinis aptikimas')
                    ->description('Sistema atpažins pasikartojančius produktų blokus XML faile (pvz. entry, product, item, offer). Jei nepavyks — įveskite kelią rankiniu būdu.')
                    ->schema([
                        Hidden::make('pigu_item_path_candidates')->dehydrated(false),
                        Hidden::make('pigu_item_count')->dehydrated(false),
                        Hidden::make('pigu_available_fields')->dehydrated(false),
                        Hidden::make('pigu_preview_rows')->dehydrated(false),
                        Actions::make([
                            Action::make('autodetectFields')
                                ->label('Aptikti laukus ir peržiūrėti XML')
                                ->action(function (Get $get, Set $set) use ($getLivewire): void {
                                    self::runXmlDetection($get, $set, $getLivewire);
                                }),
                        ]),
                        Placeholder::make('pigu_item_path_candidates_display')
                            ->label('Aptikti produktų blokai')
                            ->content(fn (Get $get): HtmlString => self::candidatesHtml($get('pigu_item_path_candidates') ?? []))
                            ->columnSpanFull(),
                        Placeholder::make('pigu_item_count_display')
                            ->label('Pasirinkto kelio produktų skaičius')
                            ->content(fn (Get $get): string => (string) ($get('pigu_item_count') ?? '—')),
                    ]),
                Select::make('config.xml_item_path')
                    ->label('Produktų kelias')
                    ->required()
                    ->searchable()
                    ->options(fn (Get $get): array => self::pathOptions($get))
                    ->getOptionLabelUsing(fn (?string $value): ?string => filled($value) ? (string) $value : null)
                    ->helperText('Pasirinkite iš aptiktų blokų. Jei sąraše nėra — įveskite kelią lauke žemiau.')
                    ->live()
                    ->afterStateUpdated(function (?string $state, Get $get, Set $set): void {
                        self::refreshCountsForSelectedPath($state, $get, $set);
                    })
                    ->rules([
                        fn (Get $get): \Closure => function (string $attribute, mixed $value, \Closure $fail) use ($get): void {
                            $path = trim((string) $value);
                            if ($path === '') {
                                $fail('Pasirinkite arba įveskite produktų kelią.');

                                return;
                            }

                            $count = $get('pigu_item_count');
                            $candidates = $get('pigu_item_path_candidates') ?? [];
                            if (! is_array($candidates) || $candidates === []) {
                                return;
                            }

                            foreach ($candidates as $candidate) {
                                if (! is_array($candidate) || ($candidate['path'] ?? null) !== $path) {
                                    continue;
                                }

                                if ((int) ($candidate['count'] ?? 0) <= 0 || (is_numeric($count) && (int) $count <= 0)) {
                                    $fail('Pasirinktas XML produktų kelias „'.$path.'“ nerado nė vieno produkto. Pasirinkite kitą kelią arba įveskite teisingą (pvz. //entry).');
                                }

                                return;
                            }

                            if (is_numeric($count) && (int) $count <= 0) {
                                $fail('Pasirinktas XML produktų kelias „'.$path.'“ nerado nė vieno produkto. Pasirinkite kitą kelią arba įveskite teisingą (pvz. //entry).');
                            }
                        },
                    ]),
                TextInput::make('pigu_manual_item_path')
                    ->label('Arba įveskite kelią rankiniu būdu')
                    ->placeholder('pvz. //entry')
                    ->datalist(fn (Get $get): array => array_keys(self::pathOptions($get)))
                    ->helperText('Naudokite, jei automatinis sąrašas netinka. Po įvedimo paspauskite „Aptikti laukus…“ dar kartą.')
                    ->live(onBlur: true)
                    ->afterStateUpdated(function (?string $state, Set $set): void {
                        $path = trim((string) $state);
                        if ($path !== '') {
                            $set('config.xml_item_path', $path);
                        }
                    })
                    ->dehydrated(false),
                Select::make('config.xml_sku_path')
                    ->label('SKU laukas')
                    ->required()
                    ->searchable()
                    ->options(fn (Get $get): array => self::fieldOptions($get)),
                Select::make('config.xml_barcode_path')
                    ->label('EAN / barkodas')
                    ->required()
                    ->searchable()
                    ->options(fn (Get $get): array => self::fieldOptions($get)),
                Select::make('config.xml_stock_path')
                    ->label('Likutis')
                    ->required()
                    ->searchable()
                    ->options(fn (Get $get): array => self::fieldOptions($get)),
                Select::make('config.xml_price_path')
                    ->label('Rekomenduojama mažmeninė kaina')
                    ->required()
                    ->searchable()
                    ->options(fn (Get $get): array => self::fieldOptions($get)),
                Select::make('config.xml_title_path')
                    ->label('Produkto pavadinimas (neprivaloma)')
                    ->searchable()
                    ->options(fn (Get $get): array => self::fieldOptions($get)),
                Placeholder::make('preview_table')
                    ->label('Peržiūra (iki 10 produktų)')
                    ->content(fn (Get $get): HtmlString => self::previewTableHtml($get('pigu_preview_rows') ?? []))
                    ->columnSpanFull(),
            ]);
    }

    private static function pricingStep(): Step
    {
        return Step::make('Kainodara')
            ->description('Rekomenduojama kaina + PVM')
            ->schema([
                Placeholder::make('pricing_help')
                    ->content('Pagal nutylėjimą: tiekėjo rekomenduojama mažmeninė kaina + PVM. Shopify produktams visada lieka Shopify kaina.')
                    ->columnSpanFull(),
                Select::make('config.pigu_offers.price_source')
                    ->label('Kainos šaltinis')
                    ->options([
                        'recommended_retail' => 'Tiekėjo rekomenduojama mažmeninė kaina',
                    ])
                    ->default('recommended_retail')
                    ->required(),
                Toggle::make('config.pigu_offers.price_includes_vat')
                    ->label('Šaltinio kaina jau su PVM')
                    ->default(false)
                    ->helperText('Jei išjungta — prie kainos pridedamas PVM.'),
                TextInput::make('config.pigu_offers.vat_rate')
                    ->label('PVM procentas')
                    ->numeric()
                    ->default(21)
                    ->required()
                    ->suffix('%'),
                TextInput::make('config.pigu_offers.price_multiplier')
                    ->label('Kainos daugiklis (neprivaloma)')
                    ->numeric()
                    ->default(1)
                    ->helperText('Pvz. 1.05 = +5%.'),
                Select::make('config.pigu_offers.price_rounding')
                    ->label('Kainos apvalinimas (neprivaloma)')
                    ->options([
                        '' => 'Be papildomo apvalinimo (2 skaitmenys)',
                        '0.05' => 'Iki 0,05',
                        '0.10' => 'Iki 0,10',
                        '1.00' => 'Iki 1,00',
                    ])
                    ->default(''),
            ]);
    }

    private static function stockStep(): Step
    {
        return Step::make('Likutis ir pristatymas')
            ->description('Sandėlio taisyklės Pigu pasiūlymams')
            ->schema([
                TextInput::make('config.pigu_offers.collection_hours')
                    ->label('Surinkimo valandos (collectionhours)')
                    ->numeric()
                    ->default(240)
                    ->required()
                    ->helperText('Kai nėra vietinio Shopify likučio — naudojama ši reikšmė (pagal nutylėjimą 240).'),
                Toggle::make('config.pigu_offers.export_supplier_only')
                    ->label('Eksportuoti produktus, kurių nėra Shopify')
                    ->default(true),
                Toggle::make('force_daily_sync')
                    ->label('Automatiškai atnaujinti XML kasdien')
                    ->default(true),
                Toggle::make('sync_enabled')
                    ->label('Leisti sinchronizaciją')
                    ->default(true),
                TextInput::make('stale_after_minutes')
                    ->label('Likučio galiojimas (minutės)')
                    ->numeric()
                    ->default(2880)
                    ->helperText('Pasibaigus — tiekėjo likutis laikomas pasenusiu ir neeksportuojamas.'),
                Placeholder::make('stock_priority_note')
                    ->content('Vietinis Shopify likutis > 0 visada turi prioritetą (24 val.). Shopify kaina niekada nekeičiama tiekėjo kaina.')
                    ->columnSpanFull(),
            ]);
    }

    private static function reviewStep(?callable $getLivewire): Step
    {
        return Step::make('Peržiūra ir aktyvavimas')
            ->description('Patikrinkite statistiką prieš įjungimą')
            ->schema([
                Placeholder::make('review_stats')
                    ->label('Statistika')
                    ->content(function (Get $get) use ($getLivewire): HtmlString {
                        $livewire = $getLivewire !== null ? $getLivewire() : null;
                        $record = is_object($livewire) && method_exists($livewire, 'getRecord')
                            ? $livewire->getRecord()
                            : null;

                        if (! $record instanceof Supplier || ! $record->exists) {
                            return new HtmlString(
                                '<p>Išsaugokite tiekėją ir paleiskite sinchronizaciją, kad pamatytumėte statistiką.</p>'
                                .'<p>Aktyvavimas įrašys nustatymus ir leis produktus įtraukti į <code>pigu-offers.xml</code> po sėkmingos sinchronizacijos.</p>'
                            );
                        }

                        $stats = app(PiguSupplierOfferStats::class)->reviewForSupplier($record);

                        return new HtmlString(
                            '<ul style="line-height:1.8">'
                            .'<li><strong>Iš viso produktų:</strong> '.$stats['total_products'].'</li>'
                            .'<li><strong>Galiojančių tiekėjo pasiūlymų:</strong> '.$stats['valid_offers'].'</li>'
                            .'<li><strong>Trūksta EAN:</strong> '.$stats['missing_ean'].'</li>'
                            .'<li><strong>Trūksta SKU:</strong> '.$stats['missing_sku'].'</li>'
                            .'<li><strong>Netinkama kaina:</strong> '.$stats['invalid_prices'].'</li>'
                            .'<li><strong>Nėra likučio:</strong> '.$stats['out_of_stock'].'</li>'
                            .'<li><strong>Jau Shopify kataloge:</strong> '.$stats['in_shopify'].'</li>'
                            .'<li><strong>Tik tiekėjo produktai:</strong> '.$stats['supplier_only'].'</li>'
                            .'<li><strong>Konfliktiniai / neaiškūs:</strong> '.$stats['ambiguous'].'</li>'
                            .'<li><strong>Numatomi papildomi Pigu pasiūlymai:</strong> '.$stats['estimated_additional_offers'].'</li>'
                            .'</ul>'
                        );
                    })
                    ->columnSpanFull(),
                Toggle::make('config.pigu_offers.enabled')
                    ->label('Aktyvuoti tiekėją Pigu pasiūlymams')
                    ->default(false)
                    ->helperText('Įjungus — po sinchronizacijos produktai gali būti įtraukti į viešą pigu-offers.xml. Pradinė peržiūra neatnaujina gyvo feed\'o automatiškai.'),
                Placeholder::make('activate_note')
                    ->content('Po išsaugojimo naudokite „Sinchronizuoti dabar“. Gyvas feed atnaujinamas tik sėkmingai sugeneravus pasiūlymus (pvz. dienos sinchronizacija arba rankinis pigu:export-offers-xml).')
                    ->columnSpanFull(),
            ]);
    }

    private static function runXmlDetection(Get $get, Set $set, ?callable $getLivewire): void
    {
        $url = trim((string) $get('endpoint_url'));
        if ($url === '') {
            Notification::make()->title('Įveskite XML adresą')->danger()->send();

            return;
        }

        try {
            $xml = app(SupplierXmlFeedClient::class)->fetch($url);
            $detector = app(PiguSupplierXmlFieldDetector::class);
            $preferredPath = trim((string) ($get('config.xml_item_path') ?? $get('pigu_manual_item_path') ?? ''));

            $detected = $preferredPath !== ''
                ? $detector->detectForItemPath($xml, $preferredPath)
                : $detector->detect($xml);

            if (($detected['item_count'] ?? 0) <= 0) {
                $set('pigu_item_path_candidates', $detected['item_path_candidates'] ?? []);
                $set('pigu_item_count', 0);
                $set('pigu_preview_rows', []);
                $set('pigu_available_fields', $detected['available_fields'] ?? []);

                $pathLabel = $detected['item_path'] ?? $preferredPath ?: '—';
                Notification::make()
                    ->title('Produktų nerasta')
                    ->body('Pasirinktas XML produktų kelias „'.$pathLabel.'“ nerado nė vieno produkto. Pasirinkite kitą kelią iš sąrašo arba įveskite teisingą (pvz. //entry).')
                    ->danger()
                    ->send();

                return;
            }

            self::applyDetectionToForm($detected, $set, $getLivewire);

            Notification::make()
                ->title('Aptikta '.$detected['item_count'].' produktų')
                ->body('Produktų kelias: '.($detected['item_path'] ?? '—'))
                ->success()
                ->send();
        } catch (Throwable $exception) {
            Notification::make()
                ->title('Nepavyko nuskaityti XML')
                ->body($exception->getMessage())
                ->danger()
                ->send();
        }
    }

    /**
     * @param  array<string, mixed>  $detected
     */
    private static function applyDetectionToForm(array $detected, Set $set, ?callable $getLivewire): void
    {
        $set('config.xml_item_path', $detected['item_path']);
        $set('config.xml_sku_path', $detected['sku_path']);
        $set('config.xml_barcode_path', $detected['barcode_path']);
        $set('config.xml_stock_path', $detected['stock_path']);
        $set('config.xml_price_path', $detected['price_path']);
        $set('config.xml_title_path', $detected['title_path']);
        $set('pigu_preview_rows', $detected['preview_rows']);
        $set('pigu_available_fields', $detected['available_fields']);
        $set('pigu_item_count', $detected['item_count']);
        $set('pigu_item_path_candidates', $detected['item_path_candidates'] ?? []);
        $set('pigu_manual_item_path', $detected['item_path']);

        if ($getLivewire !== null) {
            $livewire = $getLivewire();
            if (is_object($livewire) && property_exists($livewire, 'piguPreviewRows')) {
                $livewire->piguPreviewRows = $detected['preview_rows'];
            }
        }
    }

    private static function refreshCountsForSelectedPath(?string $state, Get $get, Set $set): void
    {
        $path = trim((string) $state);
        if ($path === '') {
            return;
        }

        $candidates = $get('pigu_item_path_candidates') ?? [];
        if (! is_array($candidates)) {
            return;
        }

        foreach ($candidates as $candidate) {
            if (! is_array($candidate) || ($candidate['path'] ?? null) !== $path) {
                continue;
            }

            $set('pigu_item_count', (int) ($candidate['count'] ?? 0));
            $set('pigu_manual_item_path', $path);

            return;
        }
    }

    /**
     * @return array<string, string>
     */
    private static function pathOptions(Get $get): array
    {
        $options = [];
        $candidates = $get('pigu_item_path_candidates') ?? [];

        if (is_array($candidates)) {
            foreach ($candidates as $candidate) {
                if (! is_array($candidate)) {
                    continue;
                }
                $path = (string) ($candidate['path'] ?? '');
                if ($path === '') {
                    continue;
                }
                $label = (string) ($candidate['label'] ?? $path);
                $options[$path] = $label;
            }
        }

        $current = $get('config.xml_item_path');
        if (filled($current) && ! isset($options[(string) $current])) {
            $options[(string) $current] = (string) $current;
        }

        return $options;
    }

    /**
     * @param  mixed  $candidates
     */
    private static function candidatesHtml(mixed $candidates): HtmlString
    {
        if (! is_array($candidates) || $candidates === []) {
            return new HtmlString('<p class="text-sm text-gray-500">Paspauskite „Aptikti laukus…“, kad sistema surastų produktų blokus (pvz. entry, product).</p>');
        }

        $html = '<ul style="margin:0;padding-left:1.1rem;line-height:1.7">';
        foreach ($candidates as $candidate) {
            if (! is_array($candidate)) {
                continue;
            }
            $label = e((string) ($candidate['label'] ?? $candidate['path'] ?? ''));
            $path = e((string) ($candidate['path'] ?? ''));
            $html .= '<li><strong>'.$label.'</strong> <span class="text-gray-500">('.$path.')</span></li>';
        }
        $html .= '</ul>';

        return new HtmlString($html);
    }

    /**
     * @return array<string, string>
     */
    private static function fieldOptions(Get $get): array
    {
        $fields = $get('pigu_available_fields') ?? [];
        if (! is_array($fields)) {
            $fields = [];
        }

        $options = [];
        foreach ($fields as $field) {
            $options[(string) $field] = (string) $field;
        }

        foreach ([
            'config.xml_sku_path',
            'config.xml_barcode_path',
            'config.xml_stock_path',
            'config.xml_price_path',
            'config.xml_title_path',
        ] as $key) {
            $value = $get($key);
            if (filled($value)) {
                $options[(string) $value] = (string) $value;
            }
        }

        return $options;
    }

    /**
     * @param  mixed  $rows
     */
    private static function previewTableHtml(mixed $rows): HtmlString
    {
        if (! is_array($rows) || $rows === []) {
            return new HtmlString('<p class="text-sm text-gray-500">Paspauskite „Aptikti laukus…“, kad pamatytumėte produktus.</p>');
        }

        $html = '<div style="overflow-x:auto"><table style="width:100%;border-collapse:collapse;font-size:13px">';
        $html .= '<thead><tr>'
            .'<th style="text-align:left;border-bottom:1px solid #ddd;padding:6px">SKU</th>'
            .'<th style="text-align:left;border-bottom:1px solid #ddd;padding:6px">EAN</th>'
            .'<th style="text-align:left;border-bottom:1px solid #ddd;padding:6px">Likutis</th>'
            .'<th style="text-align:left;border-bottom:1px solid #ddd;padding:6px">Kaina</th>'
            .'<th style="text-align:left;border-bottom:1px solid #ddd;padding:6px">Pavadinimas</th>'
            .'</tr></thead><tbody>';

        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }
            $html .= '<tr>'
                .'<td style="padding:6px;border-bottom:1px solid #eee">'.e((string) ($row['sku'] ?? '')).'</td>'
                .'<td style="padding:6px;border-bottom:1px solid #eee">'.e((string) ($row['ean'] ?? '')).'</td>'
                .'<td style="padding:6px;border-bottom:1px solid #eee">'.e((string) ($row['stock'] ?? '')).'</td>'
                .'<td style="padding:6px;border-bottom:1px solid #eee">'.e((string) ($row['price'] ?? '')).'</td>'
                .'<td style="padding:6px;border-bottom:1px solid #eee">'.e((string) ($row['title'] ?? '')).'</td>'
                .'</tr>';
        }

        $html .= '</tbody></table></div>';

        return new HtmlString($html);
    }
}
