<?php

namespace App\Services\Suppliers\Csv;

use App\Models\Supplier;
use App\Services\Suppliers\SupplierCredentialResolver;
use App\Services\Suppliers\SupplierFeedFetchException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class SupplierCsvFeedClient
{
    public function __construct(
        private readonly SupplierCredentialResolver $credentialResolver,
        private readonly CurlHttpTransport $curlTransport = new CurlHttpTransport,
        private readonly int $timeoutSeconds = 60,
        private readonly int $connectTimeoutSeconds = 15,
        private readonly int $retryTimes = 3,
        private readonly int $retrySleepMilliseconds = 500,
    ) {}

    public function fetch(Supplier $supplier): string
    {
        return match ($supplier->connector_type) {
            Supplier::CONNECTOR_CSV_URL => $this->fetchFromUrl($supplier),
            Supplier::CONNECTOR_CSV_UPLOAD => $this->readUpload($supplier),
            default => throw new RuntimeException('Supplier connector is not a CSV feed type.'),
        };
    }

    public function fetchFromUrl(Supplier $supplier): string
    {
        if (blank($supplier->endpoint_url)) {
            throw new SupplierFeedFetchException('CSV supplier endpoint URL is not configured.', [
                'supplier_code' => $supplier->code,
            ]);
        }

        if ($supplier->auth_type === Supplier::AUTH_NTLM) {
            return $this->fetchWithNtlm($supplier);
        }

        return $this->fetchWithHttpClient($supplier);
    }

    public function readUpload(Supplier $supplier): string
    {
        $path = SupplierCsvConfig::uploadedFilePath($supplier);

        if (blank($path)) {
            throw new RuntimeException('CSV upload file path is not configured.');
        }

        $disk = Storage::disk('local');

        if (! $disk->exists($path)) {
            throw new RuntimeException('Uploaded CSV file was not found in storage.');
        }

        $maxBytes = $this->maxBytes();
        $size = $disk->size($path);

        if ($size > $maxBytes) {
            throw new RuntimeException('Uploaded CSV file exceeded the configured size limit.');
        }

        $content = $disk->get($path);

        if (! is_string($content) || trim($content) === '') {
            throw new RuntimeException('Uploaded CSV file is empty or unreadable.');
        }

        return $content;
    }

    public function testConnection(Supplier $supplier): bool
    {
        try {
            $content = $this->fetch($supplier);

            return trim($content) !== '';
        } catch (\Throwable) {
            return false;
        }
    }

    private function fetchWithNtlm(Supplier $supplier): string
    {
        $credentials = $this->credentialResolver->resolveUsernamePassword($supplier);

        if ($credentials === null) {
            throw new SupplierFeedFetchException(
                'NTLM credentials are missing. Set encrypted supplier username/password or PREZIOSO_NTLM_USERNAME / PREZIOSO_NTLM_PASSWORD.',
                ['supplier_code' => $supplier->code, 'auth_type' => Supplier::AUTH_NTLM],
            );
        }

        $response = $this->curlTransport->getWithNtlm(
            url: (string) $supplier->endpoint_url,
            username: $credentials['username'],
            password: $credentials['password'],
            connectTimeoutSeconds: $this->connectTimeoutSeconds,
            timeoutSeconds: $this->timeoutSeconds,
        );

        $context = [
            'supplier_code' => $supplier->code,
            'auth_type' => Supplier::AUTH_NTLM,
            'http_status' => $response->httpStatus,
            'curl_errno' => $response->errno,
            'curl_error' => $response->error,
            'response_size' => $response->responseSize,
            'content_type' => $response->contentType,
        ];

        Log::info('CSV supplier NTLM feed fetched', $context);

        if ($response->errno !== 0) {
            throw new SupplierFeedFetchException(
                sprintf('NTLM CSV feed cURL error (%d): %s', $response->errno, $response->error ?: 'unknown error'),
                $context,
            );
        }

        if ($response->httpStatus < 200 || $response->httpStatus >= 300) {
            throw new SupplierFeedFetchException(
                sprintf('NTLM CSV feed request failed with HTTP %d.', $response->httpStatus),
                $context,
            );
        }

        if (strlen($response->body) > $this->maxBytes()) {
            throw new SupplierFeedFetchException('CSV feed response exceeded the configured size limit.', $context);
        }

        if (trim($response->body) === '') {
            throw new SupplierFeedFetchException('NTLM CSV feed response was empty.', $context);
        }

        return $response->body;
    }

    private function fetchWithHttpClient(Supplier $supplier): string
    {
        $maxBytes = $this->maxBytes();

        try {
            $response = $this->buildHttpClient($supplier)
                ->withOptions(['stream' => true])
                ->get((string) $supplier->endpoint_url);

            if (! $response->successful()) {
                throw new SupplierFeedFetchException(sprintf(
                    'CSV feed request failed with HTTP %s.',
                    $response->status(),
                ), [
                    'supplier_code' => $supplier->code,
                    'http_status' => $response->status(),
                ]);
            }

            $body = (string) $response->body();

            if (strlen($body) > $maxBytes) {
                throw new SupplierFeedFetchException('CSV feed response exceeded the configured size limit.', [
                    'supplier_code' => $supplier->code,
                    'response_size' => strlen($body),
                ]);
            }

            if (trim($body) === '') {
                throw new SupplierFeedFetchException('CSV feed response was empty.', [
                    'supplier_code' => $supplier->code,
                ]);
            }

            Log::info('CSV supplier feed fetched', [
                'supplier_code' => $supplier->code,
                'response_size' => strlen($body),
            ]);

            return $body;
        } catch (ConnectionException $exception) {
            throw new SupplierFeedFetchException('CSV feed request timed out or failed to connect.', [
                'supplier_code' => $supplier->code,
            ], 0, $exception);
        } catch (RequestException $exception) {
            throw new SupplierFeedFetchException('CSV feed request failed.', [
                'supplier_code' => $supplier->code,
            ], 0, $exception);
        }
    }

    private function buildHttpClient(Supplier $supplier): PendingRequest
    {
        $request = Http::timeout($this->timeoutSeconds)
            ->accept('text/csv,*/*')
            ->retry(
                $this->retryTimes,
                $this->retrySleepMilliseconds,
                fn ($exception): bool => $this->shouldRetry($exception),
                throw: false,
            );

        if ($supplier->auth_type === Supplier::AUTH_BEARER_TOKEN) {
            $token = $this->credentialResolver->resolveBearerToken($supplier);

            if (filled($token)) {
                return $request->withToken((string) $token);
            }
        }

        if ($supplier->auth_type === Supplier::AUTH_BASIC) {
            $credentials = $this->credentialResolver->resolveUsernamePassword($supplier);

            if ($credentials !== null) {
                return $request->withBasicAuth($credentials['username'], $credentials['password']);
            }
        }

        if ($supplier->auth_type === Supplier::AUTH_CUSTOM_HEADERS || $supplier->auth_type === Supplier::AUTH_NONE) {
            $headers = data_get($supplier->config, 'request_headers');

            if (is_array($headers) && $headers !== []) {
                $normalized = [];

                foreach ($headers as $key => $value) {
                    if (is_string($key) && filled($value)) {
                        $normalized[$key] = (string) $value;
                    }
                }

                if ($normalized !== []) {
                    return $request->withHeaders($normalized);
                }
            }
        }

        return $request;
    }

    private function shouldRetry(mixed $exception): bool
    {
        if ($exception instanceof ConnectionException) {
            return true;
        }

        if ($exception instanceof RequestException) {
            $status = $exception->response?->status();

            return $status === null || $status >= 500 || $status === 429;
        }

        return false;
    }

    private function maxBytes(): int
    {
        $maxKb = (int) config('marketplace.suppliers.csv_max_upload_kb', 10240);

        return max(1, $maxKb) * 1024;
    }
}
