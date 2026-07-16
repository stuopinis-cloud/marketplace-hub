<?php

namespace App\Services\Suppliers\Csv;

use RuntimeException;

class CurlHttpTransport
{
    /**
     * @param  array<string, string>  $headers
     */
    public function getWithNtlm(
        string $url,
        string $username,
        string $password,
        int $connectTimeoutSeconds = 15,
        int $timeoutSeconds = 60,
        array $headers = ['Accept: text/csv,*/*'],
    ): CurlHttpResponse {
        if (! function_exists('curl_init')) {
            throw new RuntimeException('cURL extension is required for NTLM supplier feeds.');
        }

        $handle = curl_init($url);

        if ($handle === false) {
            throw new RuntimeException('Unable to initialize cURL for NTLM supplier feed.');
        }

        curl_setopt_array($handle, [
            CURLOPT_HTTPGET => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_CONNECTTIMEOUT => $connectTimeoutSeconds,
            CURLOPT_TIMEOUT => $timeoutSeconds,
            CURLOPT_FAILONERROR => false,
            CURLOPT_HTTPAUTH => CURLAUTH_NTLM,
            CURLOPT_USERPWD => $username.':'.$password,
            CURLOPT_HTTPHEADER => array_values($headers),
        ]);

        $body = curl_exec($handle);
        $errno = curl_errno($handle);
        $error = (string) curl_error($handle);
        $httpStatus = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $contentType = curl_getinfo($handle, CURLINFO_CONTENT_TYPE);
        curl_close($handle);

        $bodyString = is_string($body) ? $body : '';

        return new CurlHttpResponse(
            body: $bodyString,
            httpStatus: $httpStatus,
            errno: $errno,
            error: $error,
            contentType: is_string($contentType) && $contentType !== '' ? $contentType : null,
            responseSize: strlen($bodyString),
        );
    }
}
