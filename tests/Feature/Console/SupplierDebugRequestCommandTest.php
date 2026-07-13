<?php

namespace Tests\Feature\Console;

use App\Models\Supplier;
use App\Services\Suppliers\SupplierProvisioner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SupplierDebugRequestCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_debug_request_prints_safe_helik_diagnostics(): void
    {
        config(['services.entirem.token' => 'secret-token-value']);

        app(SupplierProvisioner::class)->ensureHelikSupplier();

        $this->artisan('supplier:debug-request helik')
            ->assertSuccessful()
            ->expectsOutputToContain('endpoint: https://api.entirem.com/api/v1/stocks')
            ->expectsOutputToContain('method: POST')
            ->expectsOutputToContain('has_token: true')
            ->expectsOutputToContain('Authorization: Bearer [redacted]')
            ->expectsOutputToContain('Content-Type: application/json')
            ->expectsOutputToContain('Accept: application/json')
            ->expectsOutputToContain('body_json: {"Items":[],"Categories":[]}')
            ->expectsOutputToContain('Items: array')
            ->expectsOutputToContain('Categories: array')
            ->doesntExpectOutputToContain('secret-token-value');
    }

    public function test_debug_request_fails_for_unknown_supplier(): void
    {
        $this->artisan('supplier:debug-request helik')
            ->assertFailed();
    }

    public function test_debug_request_reports_missing_token_safely(): void
    {
        config(['services.entirem.token' => null]);

        Supplier::query()->create([
            'name' => 'Helikon / Direct-Action',
            'code' => 'helik',
            'enabled' => true,
            'connector_type' => Supplier::CONNECTOR_API,
            'endpoint_url' => 'https://api.entirem.com/api/v1/stocks',
            'auth_type' => Supplier::AUTH_BEARER_TOKEN,
            'config' => [
                'response_data_path' => 'Value',
                'request_body' => [
                    'Items' => [],
                    'Categories' => [],
                ],
            ],
        ]);

        $this->artisan('supplier:debug-request helik')
            ->assertSuccessful()
            ->expectsOutputToContain('has_token: false');
    }
}
