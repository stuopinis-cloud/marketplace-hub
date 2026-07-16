<?php

namespace Tests\Unit\Services\Suppliers\Csv;

use App\Models\Supplier;
use App\Services\Suppliers\Csv\CurlHttpResponse;
use App\Services\Suppliers\Csv\CurlHttpTransport;
use App\Services\Suppliers\Csv\SupplierCsvFeedClient;
use App\Services\Suppliers\SupplierCredentialResolver;
use App\Services\Suppliers\SupplierFeedFetchException;
use Mockery\MockInterface;
use Tests\TestCase;

class SupplierCsvFeedClientNtlmTest extends TestCase
{
    public function test_ntlm_auth_uses_curlauth_ntlm_and_userpwd(): void
    {
        config([
            'services.prezioso.ntlm_username' => 'preziosoexport',
            'services.prezioso.ntlm_password' => 'secret-from-env',
        ]);

        $supplier = new Supplier([
            'code' => Supplier::CODE_PREZIOSO,
            'connector_type' => Supplier::CONNECTOR_CSV_URL,
            'endpoint_url' => 'http://shop.coltellerieprezioso.biz/Export/MAGAZZINO.CSV',
            'auth_type' => Supplier::AUTH_NTLM,
            'credentials' => null,
            'config' => [],
        ]);

        $transport = $this->mock(CurlHttpTransport::class, function (MockInterface $mock): void {
            $mock->shouldReceive('getWithNtlm')
                ->once()
                ->withArgs(function (...$args): bool {
                    return ($args[0] ?? null) === 'http://shop.coltellerieprezioso.biz/Export/MAGAZZINO.CSV'
                        && ($args[1] ?? null) === 'preziosoexport'
                        && ($args[2] ?? null) === 'secret-from-env';
                })
                ->andReturn(new CurlHttpResponse(
                    body: "sku;qty\nA;1\n",
                    httpStatus: 200,
                    errno: 0,
                    error: '',
                    contentType: 'text/csv',
                    responseSize: 12,
                ));
        });

        $body = new SupplierCsvFeedClient(
            credentialResolver: app(SupplierCredentialResolver::class),
            curlTransport: $transport,
        )->fetchFromUrl($supplier);

        $this->assertStringContainsString('sku;qty', $body);
    }

    public function test_failed_ntlm_http_status_gives_useful_error(): void
    {
        $supplier = new Supplier([
            'code' => Supplier::CODE_PREZIOSO,
            'connector_type' => Supplier::CONNECTOR_CSV_URL,
            'endpoint_url' => 'http://shop.coltellerieprezioso.biz/Export/MAGAZZINO.CSV',
            'auth_type' => Supplier::AUTH_NTLM,
            'credentials' => [
                'username' => 'preziosoexport',
                'password' => 'bad',
            ],
            'config' => [],
        ]);

        $transport = $this->mock(CurlHttpTransport::class, function (MockInterface $mock): void {
            $mock->shouldReceive('getWithNtlm')->once()->andReturn(new CurlHttpResponse(
                body: 'Unauthorized',
                httpStatus: 401,
                errno: 0,
                error: '',
                contentType: 'text/plain',
                responseSize: 12,
            ));
        });

        try {
            new SupplierCsvFeedClient(
                credentialResolver: app(SupplierCredentialResolver::class),
                curlTransport: $transport,
            )->fetchFromUrl($supplier);
            $this->fail('Expected SupplierFeedFetchException');
        } catch (SupplierFeedFetchException $exception) {
            $this->assertStringContainsString('HTTP 401', $exception->getMessage());
            $this->assertSame(401, $exception->context['http_status']);
            $this->assertSame(Supplier::AUTH_NTLM, $exception->context['auth_type']);
        }
    }

    public function test_curl_transport_sets_curlauth_ntlm_constant(): void
    {
        $this->assertTrue(defined('CURLAUTH_NTLM'));
        $this->assertGreaterThan(0, CURLAUTH_NTLM);
    }
}
