<?php

namespace Tests\Unit\Services\Shopify;

use App\Exceptions\Shopify\ShopifyApiException;
use App\Exceptions\Shopify\ShopifyConfigurationException;
use App\Exceptions\Shopify\ShopifyGraphQlException;
use App\Exceptions\Shopify\ShopifyTokenException;
use App\Services\Shopify\ShopifyClient;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ShopifyClientTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
    }

    public function test_query_obtains_token_and_returns_decoded_response(): void
    {
        $this->fakeShopifyApi(
            shop: 'test-shop.myshopify.com',
            accessToken: 'shpat_test_token',
            graphqlResponse: [
                'data' => [
                    'shop' => [
                        'name' => 'Demo Shop',
                        'myshopifyDomain' => 'demo.myshopify.com',
                    ],
                ],
            ],
        );

        $client = $this->makeClient();

        $response = $client->query('{ shop { name } }');

        $this->assertSame('Demo Shop', $response['data']['shop']['name']);

        Http::assertSent(function ($request) {
            if (! str_contains($request->url(), '/admin/oauth/access_token')) {
                return true;
            }

            return $request->hasHeader('Content-Type', 'application/json')
                && $request['grant_type'] === 'client_credentials'
                && $request['client_id'] === 'test-client-id'
                && $request['client_secret'] === 'test-client-secret';
        });

        Http::assertSent(function ($request) {
            return str_contains($request->url(), '/admin/api/2026-01/graphql.json')
                && $request->hasHeader('X-Shopify-Access-Token', 'shpat_test_token')
                && $request['query'] === '{ shop { name } }';
        });
    }

    public function test_access_token_is_cached_for_subsequent_requests(): void
    {
        $this->fakeShopifyApi(
            shop: 'test-shop.myshopify.com',
            accessToken: 'shpat_cached_token',
            graphqlResponse: ['data' => ['shop' => ['name' => 'Demo Shop']]],
        );

        $client = $this->makeClient();

        $client->query('{ shop { name } }');
        $client->query('{ shop { name } }');

        Http::assertSentCount(3);
    }

    public function test_query_retries_graphql_once_after_unauthorized_response(): void
    {
        Http::fake([
            'test-shop.myshopify.com/admin/oauth/access_token' => Http::sequence()
                ->push(['access_token' => 'expired-token'])
                ->push(['access_token' => 'fresh-token']),
            'test-shop.myshopify.com/admin/api/2026-01/graphql.json' => Http::sequence()
                ->push('Unauthorized', 401)
                ->push(['data' => ['shop' => ['name' => 'Demo Shop']]]),
        ]);

        $client = $this->makeClient();

        $response = $client->query('{ shop { name } }');

        $this->assertSame('Demo Shop', $response['data']['shop']['name']);
        Http::assertSentCount(4);
    }

    public function test_query_throws_on_graphql_errors(): void
    {
        $this->fakeShopifyApi(
            shop: 'test-shop.myshopify.com',
            accessToken: 'shpat_test_token',
            graphqlResponse: [
                'errors' => [
                    ['message' => 'Field error'],
                ],
            ],
        );

        $client = $this->makeClient();

        $this->expectException(ShopifyGraphQlException::class);
        $this->expectExceptionMessage('Field error');

        $client->query('{ shop { name } }');
    }

    public function test_query_throws_on_persistent_http_errors(): void
    {
        Http::fake([
            'test-shop.myshopify.com/admin/oauth/access_token' => Http::response([
                'access_token' => 'shpat_test_token',
            ]),
            'test-shop.myshopify.com/admin/api/2026-01/graphql.json' => Http::sequence()
                ->push('Unauthorized', 401)
                ->push('Unauthorized', 401),
        ]);

        $client = $this->makeClient();

        $this->expectException(ShopifyApiException::class);
        $this->expectExceptionMessage('Shopify authentication failed');

        $client->query('{ shop { name } }');
    }

    public function test_token_exchange_failure_throws_clear_exception(): void
    {
        Http::fake([
            'test-shop.myshopify.com/admin/oauth/access_token' => Http::response('Unauthorized', 401),
        ]);

        $client = $this->makeClient();

        $this->expectException(ShopifyTokenException::class);
        $this->expectExceptionMessage('client credentials were rejected');

        $client->query('{ shop { name } }');
    }

    public function test_query_throws_when_configuration_is_missing(): void
    {
        $client = new ShopifyClient;

        $this->expectException(ShopifyConfigurationException::class);

        $client->query('{ shop { name } }');
    }

    public function test_shop_domain_is_normalized(): void
    {
        $this->fakeShopifyApi(
            shop: 'demo.myshopify.com',
            accessToken: 'shpat_test_token',
            graphqlResponse: ['data' => ['shop' => ['name' => 'Demo Shop']]],
        );

        $client = new ShopifyClient(
            shop: 'https://demo.myshopify.com/',
            clientId: 'test-client-id',
            clientSecret: 'test-client-secret',
            apiVersion: '2026-01',
        );

        $client->query('{ shop { name } }');

        Http::assertSent(fn ($request) => str_contains($request->url(), 'https://demo.myshopify.com/admin/oauth/access_token'));
        Http::assertSent(fn ($request) => str_contains($request->url(), 'https://demo.myshopify.com/admin/api/2026-01/graphql.json'));
    }

  /**
     * @param  array<string, mixed>  $graphqlResponse
     */
    private function fakeShopifyApi(string $shop, string $accessToken, array $graphqlResponse): void
    {
        Http::fake([
            "{$shop}/admin/oauth/access_token" => Http::response([
                'access_token' => $accessToken,
            ]),
            "{$shop}/admin/api/2026-01/graphql.json" => Http::response($graphqlResponse),
        ]);
    }

    private function makeClient(): ShopifyClient
    {
        return new ShopifyClient(
            shop: 'test-shop.myshopify.com',
            clientId: 'test-client-id',
            clientSecret: 'test-client-secret',
            apiVersion: '2026-01',
        );
    }
}
