<?php

namespace App\Services\Suppliers\Helik;

use App\Models\Supplier;
use JsonException;

class HelikEntiremRequestBuilder
{
    public const string USER_AGENT = 'MarketplaceHub-HelikFeedClient/1.0';

    /**
     * @var array<string, array<int, mixed>>
     */
    public const array DEFAULT_REQUEST_BODY = [
        'Items' => [],
        'Categories' => [],
    ];

    /**
     * @return array<string, mixed>
     */
    public function resolveRequestBody(Supplier $supplier): array
    {
        $configured = $supplier->config['request_body'] ?? null;

        if (! is_array($configured) || $configured === []) {
            return self::DEFAULT_REQUEST_BODY;
        }

        return $configured;
    }

    /**
     * @throws JsonException
     */
    public function buildJsonBody(Supplier $supplier): string
    {
        return json_encode($this->resolveRequestBody($supplier), JSON_THROW_ON_ERROR);
    }

    /**
     * @return array<string, string>
     */
    public function buildHeaders(): array
    {
        return [
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
            'User-Agent' => self::USER_AGENT,
        ];
    }

    /**
     * @return array{
     *     endpoint: string,
     *     method: string,
     *     auth_type: string,
     *     has_token: bool,
     *     headers: array<string, string>,
     *     body_json: string,
     *     body_key_types: array<string, string>
     * }
     *
     * @throws JsonException
     */
    public function describe(Supplier $supplier, bool $hasToken): array
    {
        $body = $this->resolveRequestBody($supplier);

        return [
            'endpoint' => (string) $supplier->endpoint_url,
            'method' => 'POST',
            'auth_type' => (string) $supplier->auth_type,
            'has_token' => $hasToken,
            'headers' => [
                'Authorization' => 'Bearer [redacted]',
                ...$this->buildHeaders(),
            ],
            'body_json' => $this->buildJsonBody($supplier),
            'body_key_types' => $this->describeKeyTypes($body),
        ];
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array<string, string>
     */
    public function describeKeyTypes(array $body): array
    {
        $types = [];

        foreach ($body as $key => $value) {
            $types[(string) $key] = match (true) {
                is_array($value) => 'array',
                is_null($value) => 'null',
                is_bool($value) => 'boolean',
                is_int($value) => 'integer',
                is_float($value) => 'float',
                is_string($value) => 'string',
                is_object($value) => 'object',
                default => gettype($value),
            };
        }

        return $types;
    }
}
