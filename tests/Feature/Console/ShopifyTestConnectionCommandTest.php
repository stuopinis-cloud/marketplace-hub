<?php

namespace Tests\Feature\Console;

use App\Services\Shopify\ShopifyClient;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ShopifyTestConnectionCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        $this->app->forgetInstance(ShopifyClient::class);
    }

    public function test_command_fails_when_shopify_is_not_configured(): void
    {
        config([
            'shopify.shop' => null,
            'shopify.client_id' => null,
            'shopify.client_secret' => null,
        ]);

        $this->artisan('shopify:test-connection')
            ->expectsOutputToContain('SHOPIFY_SHOP, SHOPIFY_CLIENT_ID, and SHOPIFY_CLIENT_SECRET')
            ->assertFailed();
    }

    public function test_command_succeeds_with_valid_shopify_response(): void
    {
        config([
            'shopify.shop' => 'demo.myshopify.com',
            'shopify.client_id' => 'test-client-id',
            'shopify.client_secret' => 'test-client-secret',
            'shopify.api_version' => '2026-01',
        ]);

        Http::fake([
            'demo.myshopify.com/admin/oauth/access_token' => Http::response([
                'access_token' => 'shpat_test_token',
            ]),
            'demo.myshopify.com/admin/api/2026-01/graphql.json' => Http::response([
                'data' => [
                    'shop' => [
                        'name' => 'Demo Shop',
                        'myshopifyDomain' => 'demo.myshopify.com',
                        'primaryDomain' => [
                            'url' => 'https://demo.com',
                        ],
                    ],
                ],
            ]),
        ]);

        $this->artisan('shopify:test-connection')
            ->expectsOutputToContain('Shopify connection successful')
            ->expectsOutputToContain('Demo Shop')
            ->expectsOutputToContain('demo.myshopify.com')
            ->expectsOutputToContain('https://demo.com')
            ->assertSuccessful();
    }

    public function test_command_fails_with_clear_message_on_token_exchange_error(): void
    {
        config([
            'shopify.shop' => 'demo.myshopify.com',
            'shopify.client_id' => 'invalid',
            'shopify.client_secret' => 'invalid',
            'shopify.api_version' => '2026-01',
        ]);

        Http::fake([
            'demo.myshopify.com/admin/oauth/access_token' => Http::response('Unauthorized', 401),
        ]);

        $this->artisan('shopify:test-connection')
            ->expectsOutputToContain('client credentials were rejected')
            ->assertFailed();
    }

    public function test_command_fails_with_clear_message_on_graphql_error(): void
    {
        config([
            'shopify.shop' => 'demo.myshopify.com',
            'shopify.client_id' => 'test-client-id',
            'shopify.client_secret' => 'test-client-secret',
            'shopify.api_version' => '2026-01',
        ]);

        Http::fake([
            'demo.myshopify.com/admin/oauth/access_token' => Http::response([
                'access_token' => 'shpat_test_token',
            ]),
            'demo.myshopify.com/admin/api/2026-01/graphql.json' => Http::sequence()
                ->push('Unauthorized', 401)
                ->push('Unauthorized', 401),
        ]);

        $this->artisan('shopify:test-connection')
            ->expectsOutputToContain('Shopify connection failed')
            ->assertFailed();
    }
}
