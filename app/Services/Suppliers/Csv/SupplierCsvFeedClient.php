<?php

namespace App\Services\Suppliers\Csv;

use App\Models\Supplier;
use App\Services\Suppliers\SupplierCredentialResolver;
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
        private readonly int $timeoutSeconds = 60,
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
            throw new RuntimeException('CSV supplier endpoint URL is not configured.');
        }

        $maxBytes = $this->maxBytes();

        try {
            $response = $this->buildHttpClient($supplier)
                ->withOptions(['stream' => true])
                ->get((string) $supplier->endpoint_url);

            if (! $response->successful()) {
                throw new RuntimeException(sprintf(
                    'CSV feed request failed with HTTP %s.',
                    $response->status(),
                ));
            }

            $body = (string) $response->body();

            if (strlen($body) > $maxBytes) {
                throw new RuntimeException('CSV feed response exceeded the configured size limit.');
            }

            if (trim($body) === '') {
                throw new RuntimeException('CSV feed response was empty.');
            }

            Log::info('CSV supplier feed fetched', [
                'supplier_code' => $supplier->code,
                'response_size' => strlen($body),
            ]);

            return $body;
        } catch (ConnectionException $exception) {
            throw new RuntimeException('CSV feed request timed out or failed to connect.', 0, $exception);
        } catch (RequestException $exception) {
            throw new RuntimeException('CSV feed request failed.', 0, $exception);
        }
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

    private function buildHttpClient(Supplier $supplier): PendingRequest
    {
        $request = Http::timeout($this->timeoutSeconds)
            ->retry(
                $this->retryTimes,
                $this->retrySleepMilliseconds,
                fn ($exception): bool => $this->shouldRetry($exception),
                throw: false,
            );

        $token = $this->credentialResolver->resolveBearerToken($supplier);

        if (filled($token)) {
            return $request->withToken((string) $token);
        }

        $username = data_get($supplier->credentials, 'username');
        $password = data_get($supplier->credentials, 'password');

        if (filled($username) && filled($password)) {
            return $request->withBasicAuth((string) $username, (string) $password);
        }

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
