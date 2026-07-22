<?php

namespace App\Services\Suppliers\Json;

use App\Models\Supplier;
use App\Services\Suppliers\Csv\SupplierCsvConfig;
use App\Services\Suppliers\SupplierCredentialResolver;
use App\Services\Suppliers\SupplierFeedFetchException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class SupplierJsonFeedClient
{
    public function __construct(
        private readonly SupplierCredentialResolver $credentialResolver,
        private readonly int $timeoutSeconds = 30,
        private readonly int $retryTimes = 2,
        private readonly int $retrySleepMilliseconds = 300,
    ) {}

    public function fetch(Supplier $supplier): string
    {
        if (blank($supplier->endpoint_url)) {
            throw new RuntimeException('JSON/API supplier endpoint URL is not configured.');
        }

        $method = SupplierJsonConfig::method($supplier);

        try {
            $client = $this->buildHttpClient($supplier);
            $response = $method === 'POST'
                ? $client->post((string) $supplier->endpoint_url, SupplierJsonConfig::requestBody($supplier) ?? [])
                : $client->get((string) $supplier->endpoint_url);

            if (! $response->successful()) {
                throw new SupplierFeedFetchException(sprintf(
                    'JSON feed request failed with HTTP %s.',
                    $response->status(),
                ), [
                    'supplier_code' => $supplier->code,
                    'http_status' => $response->status(),
                ]);
            }

            $body = trim((string) $response->body());

            if ($body === '') {
                throw new SupplierFeedFetchException('JSON feed response was empty.', [
                    'supplier_code' => $supplier->code,
                ]);
            }

            return $body;
        } catch (ConnectionException $exception) {
            throw new SupplierFeedFetchException('JSON feed request timed out or failed to connect.', [
                'supplier_code' => $supplier->code,
            ], 0, $exception);
        } catch (RequestException $exception) {
            throw new SupplierFeedFetchException('JSON feed request failed.', [
                'supplier_code' => $supplier->code,
            ], 0, $exception);
        }
    }

    public function testConnection(Supplier $supplier): bool
    {
        try {
            return trim($this->fetch($supplier)) !== '';
        } catch (\Throwable) {
            return false;
        }
    }

    private function buildHttpClient(Supplier $supplier): PendingRequest
    {
        $client = Http::timeout($this->timeoutSeconds)
            ->retry($this->retryTimes, $this->retrySleepMilliseconds, throw: false)
            ->acceptJson();

        return match ($supplier->auth_type) {
            Supplier::AUTH_BEARER_TOKEN => $this->withBearer($client, $supplier),
            Supplier::AUTH_BASIC => $this->withBasic($client, $supplier),
            Supplier::AUTH_CUSTOM_HEADERS => $this->withCustomHeaders($client, $supplier),
            default => $client,
        };
    }

    private function withBearer(PendingRequest $client, Supplier $supplier): PendingRequest
    {
        $token = $this->credentialResolver->resolveBearerToken($supplier);

        if (blank($token)) {
            throw new SupplierFeedFetchException('Bearer token credentials are missing.', [
                'supplier_code' => $supplier->code,
            ]);
        }

        return $client->withToken((string) $token);
    }

    private function withBasic(PendingRequest $client, Supplier $supplier): PendingRequest
    {
        $credentials = $this->credentialResolver->resolveUsernamePassword($supplier);

        if ($credentials === null) {
            throw new SupplierFeedFetchException('Basic auth credentials are missing.', [
                'supplier_code' => $supplier->code,
            ]);
        }

        return $client->withBasicAuth($credentials['username'], $credentials['password']);
    }

    private function withCustomHeaders(PendingRequest $client, Supplier $supplier): PendingRequest
    {
        $headers = SupplierCsvConfig::get($supplier, 'request_headers', []);

        if (! is_array($headers) || $headers === []) {
            return $client;
        }

        $normalized = [];

        foreach ($headers as $key => $value) {
            $normalized[(string) $key] = (string) $value;
        }

        return $client->withHeaders($normalized);
    }
}
