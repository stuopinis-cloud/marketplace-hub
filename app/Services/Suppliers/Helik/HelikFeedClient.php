<?php

namespace App\Services\Suppliers\Helik;

use App\Exceptions\Suppliers\MissingSupplierCredentialsException;
use App\Models\Supplier;
use App\Services\Suppliers\SupplierCredentialResolver;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use JsonException;
use RuntimeException;
use Throwable;

class HelikFeedClient
{
    public function __construct(
        private readonly SupplierCredentialResolver $credentialResolver,
        private readonly HelikEntiremRequestBuilder $requestBuilder,
        private readonly int $timeoutSeconds = 60,
        private readonly int $retryTimes = 3,
        private readonly int $retrySleepMilliseconds = 500,
    ) {}

    public function fetch(Supplier $supplier): string
    {
        $token = $this->credentialResolver->resolveBearerToken($supplier);
        $hasToken = filled($token);

        if (! $hasToken) {
            throw new MissingSupplierCredentialsException((string) $supplier->code);
        }

        if (blank($supplier->endpoint_url)) {
            throw new RuntimeException('Helikon supplier endpoint URL is not configured.');
        }

        $requestBody = $this->requestBuilder->resolveRequestBody($supplier);

        try {
            $requestBodyJson = $this->requestBuilder->buildJsonBody($supplier);
        } catch (JsonException $exception) {
            throw new RuntimeException('Helikon feed request body could not be encoded as JSON.', 0, $exception);
        }

        $startedAt = microtime(true);

        try {
            $response = Http::timeout($this->timeoutSeconds)
                ->withToken((string) $token)
                ->withHeaders($this->requestBuilder->buildHeaders())
                ->withBody($requestBodyJson, 'application/json')
                ->retry(
                    $this->retryTimes,
                    $this->retrySleepMilliseconds,
                    fn ($exception): bool => $this->shouldRetry($exception),
                    throw: false,
                )
                ->post((string) $supplier->endpoint_url);

            $durationMs = (int) round((microtime(true) - $startedAt) * 1000);
            $body = (string) $response->body();

            Log::info('Helikon supplier feed request completed', [
                'supplier_code' => $supplier->code,
                'status' => $response->status(),
                'duration_ms' => $durationMs,
                'response_size' => strlen($body),
            ]);

            if ($response->status() === 401 || $response->status() === 403) {
                $this->logFailedRequest($supplier, $requestBody, $requestBodyJson, $response, $hasToken);

                throw new MissingSupplierCredentialsException((string) $supplier->code);
            }

            if (! $response->successful()) {
                $this->logFailedRequest($supplier, $requestBody, $requestBodyJson, $response, $hasToken);

                throw new RuntimeException($this->formatHttpErrorMessage($response));
            }

            if (trim($body) === '') {
                throw new RuntimeException('Helikon feed response was empty.');
            }

            return $body;
        } catch (ConnectionException $exception) {
            $this->logTransportFailure($supplier, $requestBody, $requestBodyJson, $hasToken, $exception);

            throw new RuntimeException('Helikon feed request timed out or failed to connect.', 0, $exception);
        } catch (RequestException $exception) {
            $this->logTransportFailure($supplier, $requestBody, $requestBodyJson, $hasToken, $exception);

            throw new RuntimeException('Helikon feed request failed.', 0, $exception);
        }
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
     */
    public function describeRequest(Supplier $supplier): array
    {
        $hasToken = $this->credentialResolver->hasBearerToken($supplier);

        return $this->requestBuilder->describe($supplier, $hasToken);
    }

    public function testConnection(Supplier $supplier): bool
    {
        try {
            $body = $this->fetch($supplier);

            return json_decode($body, true) !== null;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * @param  array<string, mixed>  $requestBody
     */
    private function logFailedRequest(
        Supplier $supplier,
        array $requestBody,
        string $requestBodyJson,
        Response $response,
        bool $hasToken,
    ): void {
        Log::warning('Helikon supplier feed request failed', $this->buildFailureContext(
            supplier: $supplier,
            requestBody: $requestBody,
            requestBodyJson: $requestBodyJson,
            hasToken: $hasToken,
            response: $response,
        ));
    }

    /**
     * @param  array<string, mixed>  $requestBody
     */
    private function logTransportFailure(
        Supplier $supplier,
        array $requestBody,
        string $requestBodyJson,
        bool $hasToken,
        Throwable $exception,
    ): void {
        Log::warning('Helikon supplier feed transport failed', [
            ...$this->buildFailureContext(
                supplier: $supplier,
                requestBody: $requestBody,
                requestBodyJson: $requestBodyJson,
                hasToken: $hasToken,
            ),
            'exception_class' => $exception::class,
            'exception_message' => $exception->getMessage(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $requestBody
     * @return array<string, mixed>
     */
    private function buildFailureContext(
        Supplier $supplier,
        array $requestBody,
        string $requestBodyJson,
        bool $hasToken,
        ?Response $response = null,
    ): array {
        $headers = $this->requestBuilder->buildHeaders();

        $context = [
            'supplier_code' => $supplier->code,
            'endpoint' => (string) $supplier->endpoint_url,
            'method' => 'POST',
            'auth_type' => (string) $supplier->auth_type,
            'has_token' => $hasToken,
            'request_body_keys' => array_keys($requestBody),
            'request_body_json' => $requestBodyJson,
            'content_type' => $headers['Content-Type'],
            'accept' => $headers['Accept'],
        ];

        if ($response === null) {
            return $context;
        }

        $responseBody = (string) $response->body();

        return [
            ...$context,
            'response_status' => $response->status(),
            'response_content_type' => $response->header('Content-Type'),
            'response_body_excerpt' => $this->truncateResponseBody($responseBody),
        ];
    }

    private function formatHttpErrorMessage(Response $response): string
    {
        $excerpt = $this->truncateResponseBody((string) $response->body());

        if ($excerpt === '') {
            return sprintf('HTTP %s: empty response body', $response->status());
        }

        return sprintf('HTTP %s: %s', $response->status(), $excerpt);
    }

    private function truncateResponseBody(string $body, int $limit = 1000): string
    {
        $normalized = preg_replace('/\s+/u', ' ', trim($body)) ?? '';

        if ($normalized === '') {
            return '';
        }

        if (strlen($normalized) <= $limit) {
            return $normalized;
        }

        return substr($normalized, 0, $limit).'...';
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
