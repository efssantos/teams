<?php

namespace GlpiPlugin\Teams\Api;

use GlpiPlugin\Teams\Exception\TeamsIntegrationException;

final class CurlHttpClient implements HttpClientInterface
{
    public function request(string $method, string $url, array $headers = [], array|string|null $body = null): HttpResponse
    {
        $parts = parse_url($url);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower((string) ($parts['host'] ?? ''));
        $isLoopback = in_array($host, ['localhost', '127.0.0.1', '::1'], true);
        if ($scheme !== 'https' && !($scheme === 'http' && $isLoopback)) {
            throw new TeamsIntegrationException('Outbound OAuth requests must use HTTPS.');
        }

        if (!function_exists('curl_init')) {
            throw new TeamsIntegrationException('The PHP cURL extension is required.');
        }

        $handle = curl_init($url);
        if ($handle === false) {
            throw new TeamsIntegrationException('Unable to initialize the HTTP client.');
        }

        $requestHeaders = array_merge(
            ['Accept: application/json'],
            $headers
        );

        $options = [
            CURLOPT_CUSTOMREQUEST  => strtoupper($method),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER         => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HTTPHEADER     => $requestHeaders,
            CURLOPT_USERAGENT      => 'GLPI-Microsoft-Teams-Integration/0.1',
        ];

        if ($body !== null) {
            $options[CURLOPT_POSTFIELDS] = is_array($body)
                ? http_build_query($body, '', '&', PHP_QUERY_RFC3986)
                : $body;
            if (is_array($body)) {
                $options[CURLOPT_HTTPHEADER][] = 'Content-Type: application/x-www-form-urlencoded';
            }
        }

        curl_setopt_array($handle, $options);
        $rawResponse = curl_exec($handle);
        $statusCode = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $headerSize = (int) curl_getinfo($handle, CURLINFO_HEADER_SIZE);
        $error = curl_error($handle);
        curl_close($handle);

        if ($rawResponse === false) {
            throw new TeamsIntegrationException('The OAuth provider could not be reached: ' . $error);
        }

        $rawResponse = (string) $rawResponse;
        $headerBlock = substr($rawResponse, 0, $headerSize);
        $responseBody = substr($rawResponse, $headerSize);
        $responseHeaders = [];
        foreach (preg_split('/\r\n|\n|\r/', $headerBlock) ?: [] as $headerLine) {
            if (str_contains($headerLine, ':')) {
                [$name, $value] = explode(':', $headerLine, 2);
                $responseHeaders[strtolower(trim($name))] = trim($value);
            }
        }

        return new HttpResponse($statusCode, $responseBody, $responseHeaders);
    }
}
