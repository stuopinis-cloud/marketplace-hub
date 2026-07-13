<?php

namespace App\Services\Suppliers;

use App\Models\Supplier;
use App\Services\Suppliers\Helik\HelikFeedClient;
use App\Services\Suppliers\Mtac\MtacFeedClient;

class SupplierConnectionTester
{
    public function __construct(
        private readonly MtacFeedClient $mtacFeedClient,
        private readonly HelikFeedClient $helikFeedClient,
    ) {}

    public function test(Supplier $supplier): bool
    {
        if (blank($supplier->endpoint_url)) {
            return false;
        }

        return match ($supplier->connector_type) {
            Supplier::CONNECTOR_API => $this->helikFeedClient->testConnection($supplier),
            Supplier::CONNECTOR_XML_URL => $this->mtacFeedClient->testConnection((string) $supplier->endpoint_url),
            default => false,
        };
    }
}
