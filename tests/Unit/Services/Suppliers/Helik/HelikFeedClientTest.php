<?php

namespace Tests\Unit\Services\Suppliers\Helik;

use App\Exceptions\Suppliers\MissingSupplierCredentialsException;
use App\Models\Supplier;
use App\Services\Suppliers\Helik\HelikFeedClient;
use App\Services\Suppliers\Helik\HelikResponseParser;
use App\Services\Suppliers\SupplierCredentialResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class HelikFeedClientTest extends TestCase
{
    use RefreshDatabase;

    public function test_posts_with_bearer_auth_and_logs_without_token(): void
    {
        config(['services.entirem.token' => 'secret-token-value']);

        Http::fake([
            'api.entirem.com/*' => Http::response(['Value' => [['SKU' => 'ABC', 'Quantity' => 3]]], 200),
        ]);

        Log::spy();

        $supplier = $this->makeSupplier();
        $body = app(HelikFeedClient::class)->fetch($supplier);

        $this->assertStringContainsString('ABC', $body);

        Http::assertSent(function ($request): bool {
            return $request->method() === 'POST'
                && $request->hasHeader('Authorization', 'Bearer secret-token-value')
                && $request->url() === 'https://api.entirem.com/api/v1/stocks';
        });

        Log::shouldHaveReceived('info')
            ->once()
            ->with('Helikon supplier feed request completed', \Mockery::on(function (array $context): bool {
                return ($context['status'] ?? null) === 200
                    && ! str_contains(json_encode($context), 'secret-token-value');
            }));
    }

    public function test_missing_credentials_fail_clearly(): void
    {
        $supplier = $this->makeSupplier(['credentials' => null]);
        config(['services.entirem.token' => null]);

        $this->expectException(MissingSupplierCredentialsException::class);
        $this->expectExceptionMessage('missing_supplier_credentials');

        app(HelikFeedClient::class)->fetch($supplier);
    }

    public function test_http_500_preserves_previous_stock_by_throwing_before_writes(): void
    {
        config(['services.entirem.token' => 'secret-token-value']);

        Http::fake([
            'api.entirem.com/*' => Http::sequence()
                ->push('server error', 500)
                ->push('server error', 500)
                ->push('server error', 500)
                ->push('server error', 500),
        ]);

        $supplier = $this->makeSupplier();

        try {
            app(HelikFeedClient::class)->fetch($supplier);
            $this->fail('Expected fetch to fail.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('HTTP 500', $exception->getMessage());
        }
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function makeSupplier(array $overrides = []): Supplier
    {
        return Supplier::query()->create(array_merge([
            'name' => 'Helikon / Direct-Action',
            'code' => 'helik',
            'enabled' => true,
            'connector_type' => Supplier::CONNECTOR_API,
            'endpoint_url' => 'https://api.entirem.com/api/v1/stocks',
            'auth_type' => Supplier::AUTH_BEARER_TOKEN,
            'config' => ['request_body' => []],
        ], $overrides));
    }
}
