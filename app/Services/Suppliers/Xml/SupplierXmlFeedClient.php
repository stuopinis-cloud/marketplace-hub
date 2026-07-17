<?php

namespace App\Services\Suppliers\Xml;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class SupplierXmlFeedClient
{
    public function __construct(
        private readonly int $timeoutSeconds = 60,
        private readonly int $retryTimes = 3,
        private readonly int $retrySleepMilliseconds = 500,
    ) {}

    public function fetch(string $url): string
    {
        try {
            $response = Http::timeout($this->timeoutSeconds)
                ->retry(
                    $this->retryTimes,
                    $this->retrySleepMilliseconds,
                    fn ($exception, $request): bool => $this->shouldRetry($exception),
                    throw: false,
                )
                ->get($url);

            if (! $response->successful()) {
                throw new RuntimeException(sprintf(
                    'XML feed request failed with HTTP %s.',
                    $response->status(),
                ));
            }

            $body = trim((string) $response->body());

            if ($body === '') {
                throw new RuntimeException('XML feed response was empty.');
            }

            return $body;
        } catch (ConnectionException $exception) {
            throw new RuntimeException('XML feed request timed out or failed to connect.', 0, $exception);
        } catch (RequestException $exception) {
            throw new RuntimeException('XML feed request failed.', 0, $exception);
        }
    }

    public function testConnection(string $url): bool
    {
        $response = Http::timeout($this->timeoutSeconds)->get($url);

        return $response->successful() && trim((string) $response->body()) !== '';
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
