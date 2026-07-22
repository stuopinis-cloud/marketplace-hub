<?php

namespace App\Services\Suppliers;

use App\Models\Supplier;
use App\Services\Suppliers\Csv\SupplierCsvFeedClient;
use App\Services\Suppliers\Helik\HelikFeedClient;
use App\Services\Suppliers\Json\SupplierJsonFeedClient;
use App\Services\Suppliers\Mtac\MtacFeedClient;
use App\Services\Suppliers\Xml\SupplierXmlFeedClient;

class SupplierConnectionTester
{
    public function __construct(
        private readonly MtacFeedClient $mtacFeedClient,
        private readonly HelikFeedClient $helikFeedClient,
        private readonly SupplierCsvFeedClient $csvFeedClient,
        private readonly SupplierXmlFeedClient $xmlFeedClient,
        private readonly SupplierJsonFeedClient $jsonFeedClient,
    ) {}

    public function test(Supplier $supplier): bool
    {
        return match ($supplier->connector_type) {
            Supplier::CONNECTOR_MTAC => blank($supplier->endpoint_url)
                ? false
                : $this->mtacFeedClient->testConnection((string) $supplier->endpoint_url),
            Supplier::CONNECTOR_HELIK_API => $this->helikFeedClient->testConnection($supplier),
            Supplier::CONNECTOR_API => $supplier->code === Supplier::CODE_HELIK
                ? $this->helikFeedClient->testConnection($supplier)
                : $this->jsonFeedClient->testConnection($supplier),
            Supplier::CONNECTOR_JSON_API => $this->jsonFeedClient->testConnection($supplier),
            Supplier::CONNECTOR_XML_URL => blank($supplier->endpoint_url)
                ? false
                : ($supplier->code === Supplier::CODE_MTAC
                    ? $this->mtacFeedClient->testConnection((string) $supplier->endpoint_url)
                    : $this->xmlFeedClient->testConnection((string) $supplier->endpoint_url)),
            Supplier::CONNECTOR_CSV_URL, Supplier::CONNECTOR_CSV_UPLOAD => $this->csvFeedClient->testConnection($supplier),
            default => false,
        };
    }
}
