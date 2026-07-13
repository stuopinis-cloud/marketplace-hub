<?php

namespace App\Services\Suppliers\Helik;

use App\Exceptions\Suppliers\MissingSupplierCredentialsException;
use App\Models\Supplier;
use App\Services\Suppliers\SupplierCredentialResolver;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class HelikFeedClient
{
    public function __construct(
        private readonly SupplierCredentialResolver $credentialResolver,
        private readonly int $timeoutSeconds = 60,
        private readonly int $retryTimes = 3,
        private readonly int $retrySleepMilliseconds = 500,
    ) {}

    public function fetch(Supplier $supplier): string
    {
        $token = $this->credentialResolver->resolveBearerToken($supplier);

        if (! filled($token)) {
            throw new MissingSupplierCredentialsException((string) $supplier->code);
        }

        if (blank($supplier->endpoint_url)) {
            throw new RuntimeException('Helikon supplier endpoint URL is not configured.');
        }

        $requestBody = $supplier->config['request_body'] ?? [];
        $startedAt = microtime(true);

        try {
            $response = Http::timeout($this->timeoutSeconds)
                ->withToken($token)
                ->retry(
                    $this->retryTimes,
                    $this->retrySleepMilliseconds,
                    fn ($exception): bool => $this->shouldRetry($exception),
                    throw: false,
                )
                ->post((string) $supplier->endpoint_url, is_array($requestBody) ? $requestBody : []);

            $durationMs = (int) round((microtime(true) - $startedAt) * 1000);
            $body = (string) $response->body();

            Log::info('Helikon supplier feed request completed', [
                'supplier_code' => $supplier->code,
                'status' => $response->status(),
                'duration_ms' => $durationMs,
                'response_size' => strlen($body),
            ]);

            if ($response->status() === 401 || $response->status() === 403) {
                throw new MissingSupplierCredentialsException((string) $supplier->code);
            }

            if (! $response->successful()) {
                throw new RuntimeException(sprintf(
                    'Helikon feed request failed with HTTP %s.',
                    $response->status(),
                ));
            }

            if (trim($body) === '') {
                throw new RuntimeException('Helikon feed response was empty.');
            }

            return $body;
        } catch (ConnectionException $exception) {
            throw new RuntimeException('Helikon feed request timed out or failed to connect.', 0, $exception);
        } catch (RequestException $exception) {
            throw new RuntimeException('Helikon feed request failed.', 0, $exception);
        }
    }

    public function testConnection(Supplier $supplier): bool
    {
        try {
            $body = $this->fetch($supplier);

            return json_decode($body, true) !== null;
        } catch (\Throwable) {
            return false;
        }
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
}
