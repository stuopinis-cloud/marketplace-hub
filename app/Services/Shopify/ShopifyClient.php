<?php

namespace App\Services\Shopify;

use App\Exceptions\Shopify\ShopifyApiException;
use App\Exceptions\Shopify\ShopifyConfigurationException;
use App\Exceptions\Shopify\ShopifyGraphQlException;
use App\Exceptions\Shopify\ShopifyTokenException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class ShopifyClient
{
    private const int RETRY_TIMES = 3;

    private const int RETRY_SLEEP_MS = 250;

    public function __construct(
        private readonly ?string $shop = null,
        private readonly ?string $clientId = null,
        private readonly ?string $clientSecret = null,
        private readonly ?string $apiVersion = null,
    ) {}

    public static function fromConfig(): self
    {
        return new self(
            shop: config('shopify.shop'),
            clientId: config('shopify.client_id'),
            clientSecret: config('shopify.client_secret'),
            apiVersion: config('shopify.api_version'),
        );
    }

    /**
     * Execute a Shopify Admin GraphQL request.
     *
     * @param  array<string, mixed>  $variables
     * @return array<string, mixed>
     */
    public function query(string $query, array $variables = []): array
    {
        $this->ensureConfigured();

        try {
            return $this->executeQuery($query, $variables, $this->getAccessToken());
        } catch (ShopifyApiException $exception) {
            if (! in_array($exception->getCode(), [401, 403], true)) {
                throw $exception;
            }

            $this->clearAccessToken();

            return $this->executeQuery($query, $variables, $this->getAccessToken(forceRefresh: true));
        }
    }

    public function getAccessToken(bool $forceRefresh = false): string
    {
        $this->ensureConfigured();

        $cacheKey = $this->accessTokenCacheKey();

        if (! $forceRefresh && Cache::has($cacheKey)) {
            $cachedToken = Cache::get($cacheKey);

            if (is_string($cachedToken) && $cachedToken !== '') {
                return $cachedToken;
            }
        }

        $token = $this->requestAccessToken();

        Cache::put($cacheKey, $token, now()->addHours($this->tokenCacheTtlHours()));

        return $token;
    }

    public function clearAccessToken(): void
    {
        Cache::forget($this->accessTokenCacheKey());
    }

    public function isConfigured(): bool
    {
        return filled($this->shop())
            && filled($this->clientId())
            && filled($this->clientSecret());
    }

    private function requestAccessToken(): string
    {
        $payload = [
            'grant_type' => 'client_credentials',
            'client_id' => $this->clientId(),
            'client_secret' => $this->clientSecret(),
        ];

        try {
            $response = Http::withHeaders([
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ])
                ->retry(
                    self::RETRY_TIMES,
                    self::RETRY_SLEEP_MS,
                    function (\Throwable $exception) {
                        if ($exception instanceof ConnectionException) {
                            return true;
                        }

                        if ($exception instanceof RequestException) {
                            return $exception->response->serverError() || $exception->response->status() === 429;
                        }

                        return false;
                    },
                    throw: false,
                )
                ->post($this->tokenEndpoint(), $payload);
        } catch (ConnectionException $exception) {
            throw ShopifyTokenException::exchangeFailed(0, $exception->getMessage());
        }

        if ($response->failed()) {
            throw ShopifyTokenException::exchangeFailed($response->status(), $response->body());
        }

        $decoded = $response->json();

        if (! is_array($decoded)) {
            throw ShopifyTokenException::invalidResponse('expected JSON object');
        }

        $accessToken = $decoded['access_token'] ?? null;

        if (! is_string($accessToken) || $accessToken === '') {
            throw ShopifyTokenException::missingAccessToken();
        }

        return $accessToken;
    }

    /**
     * @param  array<string, mixed>  $variables
     * @return array<string, mixed>
     */
    private function executeQuery(string $query, array $variables, string $accessToken): array
    {
        $payload = ['query' => $query];

        if ($variables !== []) {
            $payload['variables'] = $variables;
        }

        try {
            $response = Http::withHeaders([
                'Content-Type' => 'application/json',
                'X-Shopify-Access-Token' => $accessToken,
            ])
                ->retry(
                    self::RETRY_TIMES,
                    self::RETRY_SLEEP_MS,
                    function (\Throwable $exception) {
                        if ($exception instanceof ConnectionException) {
                            return true;
                        }

                        if ($exception instanceof RequestException) {
                            return $exception->response->serverError() || $exception->response->status() === 429;
                        }

                        return false;
                    },
                    throw: false,
                )
                ->post($this->graphqlEndpoint(), $payload);
        } catch (ConnectionException $exception) {
            throw ShopifyApiException::fromHttpStatus(0, $exception->getMessage());
        }

        if ($response->failed()) {
            throw ShopifyApiException::fromHttpStatus($response->status(), $response->body());
        }

        $decoded = $response->json();

        if (! is_array($decoded)) {
            throw ShopifyApiException::invalidResponse('expected JSON object');
        }

        if (! empty($decoded['errors']) && is_array($decoded['errors'])) {
            throw ShopifyGraphQlException::fromErrors($decoded['errors']);
        }

        return $decoded;
    }

    private function ensureConfigured(): void
    {
        if (blank($this->shop()) && blank($this->clientId()) && blank($this->clientSecret())) {
            throw ShopifyConfigurationException::missingCredentials();
        }

        if (blank($this->shop())) {
            throw ShopifyConfigurationException::missingShop();
        }

        if (blank($this->clientId())) {
            throw ShopifyConfigurationException::missingClientId();
        }

        if (blank($this->clientSecret())) {
            throw ShopifyConfigurationException::missingClientSecret();
        }
    }

    private function tokenEndpoint(): string
    {
        return sprintf('https://%s/admin/oauth/access_token', $this->normalizedShop());
    }

    private function graphqlEndpoint(): string
    {
        return sprintf(
            'https://%s/admin/api/%s/graphql.json',
            $this->normalizedShop(),
            $this->apiVersion(),
        );
    }

    private function accessTokenCacheKey(): string
    {
        return sprintf(
            'shopify.access_token.%s.%s',
            $this->normalizedShop(),
            sha1((string) $this->clientId()),
        );
    }

    private function normalizedShop(): string
    {
        return Str::of((string) $this->shop())
            ->replaceStart('https://', '')
            ->replaceStart('http://', '')
            ->trim('/')
            ->toString();
    }

    private function shop(): ?string
    {
        return $this->shop;
    }

    private function clientId(): ?string
    {
        return $this->clientId;
    }

    private function clientSecret(): ?string
    {
        return $this->clientSecret;
    }

    private function apiVersion(): string
    {
        return $this->apiVersion ?? config('shopify.api_version', '2026-01');
    }

    private function tokenCacheTtlHours(): int
    {
        return (int) config('shopify.token_cache_ttl_hours', 23);
    }
}
