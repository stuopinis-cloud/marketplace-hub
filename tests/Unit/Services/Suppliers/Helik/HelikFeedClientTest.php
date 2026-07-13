<?php

namespace Tests\Unit\Services\Suppliers\Helik;

use App\Exceptions\Suppliers\MissingSupplierCredentialsException;
use App\Models\Supplier;
use App\Services\Suppliers\Helik\HelikEntiremRequestBuilder;
use App\Services\Suppliers\Helik\HelikFeedClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class HelikFeedClientTest extends TestCase
{
    use RefreshDatabase;

    public function test_posts_with_exact_json_headers_and_body(): void
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
                && $request->url() === 'https://api.entirem.com/api/v1/stocks'
                && $request->hasHeader('Authorization', 'Bearer secret-token-value')
                && $request->hasHeader('Content-Type', 'application/json')
                && $request->hasHeader('Accept', 'application/json')
                && $request->body() === '{"Items":[],"Categories":[]}';
        });

        Log::shouldHaveReceived('info')
            ->once()
            ->with('Helikon supplier feed request completed', \Mockery::on(function (array $context): bool {
                return ($context['status'] ?? null) === 200
                    && ! str_contains(json_encode($context), 'secret-token-value');
            }));
    }

    public function test_default_request_body_used_when_config_request_body_is_empty(): void
    {
        config(['services.entirem.token' => 'secret-token-value']);

        Http::fake([
            'api.entirem.com/*' => Http::response(['Value' => []], 200),
        ]);

        $supplier = $this->makeSupplier([
            'config' => ['request_body' => []],
        ]);

        app(HelikFeedClient::class)->fetch($supplier);

        Http::assertSent(fn ($request): bool => $request->body() === '{"Items":[],"Categories":[]}');
    }

    public function test_missing_credentials_fail_clearly(): void
    {
        $supplier = $this->makeSupplier(['credentials' => null]);
        config(['services.entirem.token' => null]);

        $this->expectException(MissingSupplierCredentialsException::class);
        $this->expectExceptionMessage('missing_supplier_credentials');

        app(HelikFeedClient::class)->fetch($supplier);
    }

    public function test_http_400_with_json_error_body_surfaces_safe_excerpt(): void
    {
        config(['services.entirem.token' => 'secret-token-value']);

        Http::fake([
            'api.entirem.com/*' => Http::response(
                ['message' => 'Invalid request body', 'code' => 'bad_request'],
                400,
                ['Content-Type' => 'application/json'],
            ),
        ]);

        Log::spy();

        $supplier = $this->makeSupplier();

        try {
            app(HelikFeedClient::class)->fetch($supplier);
            $this->fail('Expected fetch to fail.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('HTTP 400:', $exception->getMessage());
            $this->assertStringContainsString('Invalid request body', $exception->getMessage());
        }

        Log::shouldHaveReceived('warning')
            ->once()
            ->with('Helikon supplier feed request failed', \Mockery::on(function (array $context): bool {
                return ($context['response_status'] ?? null) === 400
                    && ($context['request_body_json'] ?? null) === '{"Items":[],"Categories":[]}'
                    && ($context['content_type'] ?? null) === 'application/json'
                    && ($context['accept'] ?? null) === 'application/json'
                    && ($context['has_token'] ?? null) === true
                    && str_contains((string) ($context['response_body_excerpt'] ?? ''), 'Invalid request body')
                    && ! str_contains(json_encode($context), 'secret-token-value');
            }));
    }

    public function test_http_400_with_plain_text_error_body_surfaces_safe_excerpt(): void
    {
        config(['services.entirem.token' => 'secret-token-value']);

        Http::fake([
            'api.entirem.com/*' => Http::response('Bad Request: malformed payload', 400, [
                'Content-Type' => 'text/plain',
            ]),
        ]);

        $supplier = $this->makeSupplier();

        try {
            app(HelikFeedClient::class)->fetch($supplier);
            $this->fail('Expected fetch to fail.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('HTTP 400: Bad Request: malformed payload', $exception->getMessage());
        }
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
            $this->assertStringContainsString('HTTP 500:', $exception->getMessage());
            $this->assertStringContainsString('server error', $exception->getMessage());
        }
    }

    public function test_describe_request_never_exposes_token(): void
    {
        config(['services.entirem.token' => 'secret-token-value']);

        $supplier = $this->makeSupplier();
        $description = app(HelikFeedClient::class)->describeRequest($supplier);

        $this->assertTrue($description['has_token']);
        $this->assertSame('Bearer [redacted]', $description['headers']['Authorization']);
        $this->assertSame('{"Items":[],"Categories":[]}', $description['body_json']);
        $this->assertSame([
            'Items' => 'array',
            'Categories' => 'array',
        ], $description['body_key_types']);
        $this->assertStringNotContainsString('secret-token-value', json_encode($description));
    }

    public function test_success_response_parses_value_rows(): void
    {
        config(['services.entirem.token' => 'secret-token-value']);

        Http::fake([
            'api.entirem.com/*' => Http::response([
                'Value' => [
                    ['SKU' => 'HEL-1', 'Quantity' => 4],
                ],
            ], 200),
        ]);

        $supplier = $this->makeSupplier();
        $body = app(HelikFeedClient::class)->fetch($supplier);

        $decoded = json_decode($body, true);

        $this->assertIsArray($decoded);
        $this->assertSame('HEL-1', $decoded['Value'][0]['SKU']);
        $this->assertSame(4, $decoded['Value'][0]['Quantity']);
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
            'config' => [
                'response_data_path' => 'Value',
                'request_body' => [
                    'Items' => [],
                    'Categories' => [],
                ],
            ],
        ], $overrides));
    }
}
