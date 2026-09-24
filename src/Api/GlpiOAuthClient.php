<?php

namespace GlpiPlugin\Teams\Api;

use GlpiPlugin\Teams\Exception\TeamsIntegrationException;

final class GlpiOAuthClient
{
    public function __construct(private readonly HttpClientInterface $httpClient)
    {
    }

    public function buildAuthorizationUrl(array $configuration, string $state): string
    {
        $baseUrl = $this->getBaseUrl($configuration);
        $clientId = trim((string) ($configuration['glpi_api_client_id'] ?? ''));
        $redirectUri = trim((string) ($configuration['glpi_redirect_uri'] ?? ''));
        $scope = trim((string) ($configuration['glpi_oauth_scope'] ?? 'api user email'));
        $this->validateRedirectUri($redirectUri);

        if ($clientId === '' || $redirectUri === '' || $scope === '') {
            throw new TeamsIntegrationException('GLPI OAuth client ID, redirect URI and scope are required.');
        }

        $query = http_build_query([
            'response_type' => 'code',
            'client_id'     => $clientId,
            'redirect_uri'  => $redirectUri,
            'scope'         => $scope,
            'state'         => $state,
        ], '', '&', PHP_QUERY_RFC3986);

        return $baseUrl . '/api.php/authorize?' . $query;
    }

    public function exchangeAuthorizationCode(array $configuration, string $code): array
    {
        $baseUrl = $this->getBaseUrl($configuration);
        $clientId = trim((string) ($configuration['glpi_api_client_id'] ?? ''));
        $clientSecret = trim((string) ($configuration['glpi_api_client_secret'] ?? ''));
        $redirectUri = trim((string) ($configuration['glpi_redirect_uri'] ?? ''));
        $this->validateRedirectUri($redirectUri);

        if ($clientId === '' || $clientSecret === '' || $redirectUri === '') {
            throw new TeamsIntegrationException('GLPI OAuth credentials and redirect URI are required.');
        }

        $response = $this->httpClient->request(
            'POST',
            $baseUrl . '/api.php/token',
            ['Content-Type: application/x-www-form-urlencoded'],
            [
                'grant_type'    => 'authorization_code',
                'client_id'     => $clientId,
                'client_secret' => $clientSecret,
                'code'          => $code,
                'redirect_uri'  => $redirectUri,
            ]
        );

        $payload = $response->json();
        if ($response->statusCode < 200 || $response->statusCode >= 300) {
            throw new TeamsIntegrationException('GLPI OAuth token exchange failed.');
        }

        foreach (['access_token', 'token_type', 'expires_in'] as $field) {
            if (!isset($payload[$field]) || !is_scalar($payload[$field]) || (string) $payload[$field] === '') {
                throw new TeamsIntegrationException('GLPI OAuth token response is incomplete.');
            }
        }

        return $payload;
    }

    private function getBaseUrl(array $configuration): string
    {
        $baseUrl = rtrim(trim((string) ($configuration['base_url'] ?? '')), '/');
        $parts = parse_url($baseUrl);

        if ($baseUrl === '' || !is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
            throw new TeamsIntegrationException('A valid GLPI base URL is required.');
        }

        $scheme = strtolower((string) $parts['scheme']);
        $host = strtolower((string) $parts['host']);
        $isLoopback = in_array($host, ['localhost', '127.0.0.1', '::1'], true);
        if ($scheme !== 'https' && !($scheme === 'http' && $isLoopback)) {
            throw new TeamsIntegrationException('The GLPI base URL must use HTTPS.');
        }

        return $baseUrl;
    }

    private function validateRedirectUri(string $redirectUri): void
    {
        $parts = parse_url($redirectUri);
        if ($redirectUri === '' || !is_array($parts) || empty($parts['host'])) {
            throw new TeamsIntegrationException('The GLPI OAuth redirect URI must use HTTPS.');
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower((string) ($parts['host'] ?? ''));
        $isLoopback = in_array($host, ['localhost', '127.0.0.1', '::1'], true);

        if ($scheme !== 'https' && !($scheme === 'http' && $isLoopback)) {
            throw new TeamsIntegrationException('The GLPI OAuth redirect URI must use HTTPS.');
        }
    }
}
