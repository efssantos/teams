<?php

namespace GlpiPlugin\Teams\Api;

use GlpiPlugin\Teams\Exception\ProviderHttpException;
use GlpiPlugin\Teams\Exception\TeamsIntegrationException;
use GlpiPlugin\Teams\Service\ConfigurationService;

final class TeamsAccessTokenProvider
{
    private ?string $accessToken = null;
    private int $expiresAt = 0;

    public function __construct(
        private readonly ConfigurationService $configuration,
        private readonly HttpClientInterface $httpClient
    ) {
    }

    public function getAccessToken(): string
    {
        if ($this->accessToken !== null && time() < $this->expiresAt) {
            return $this->accessToken;
        }

        $config = $this->configuration->get();
        $tenantId = trim((string) ($config['tenant_id'] ?? ''));
        $clientId = trim((string) ($config['bot_app_id'] ?? ''));
        $clientSecret = trim((string) ($config['bot_app_secret'] ?? ''));

        if ($tenantId === '' || $clientId === '' || $clientSecret === '') {
            throw new TeamsIntegrationException('Teams bot OAuth configuration is incomplete.');
        }

        $url = 'https://login.microsoftonline.com/' . rawurlencode($tenantId) . '/oauth2/v2.0/token';
        try {
            $response = $this->httpClient->request('POST', $url, [], [
                'grant_type'    => 'client_credentials',
                'client_id'     => $clientId,
                'client_secret' => $clientSecret,
                'scope'         => 'https://api.botframework.com/.default',
            ]);
        } catch (TeamsIntegrationException $exception) {
            throw new ProviderHttpException('Microsoft Entra token endpoint could not be reached.', 0, null);
        }

        if ($response->statusCode === 429 || $response->statusCode >= 500) {
            throw new ProviderHttpException(
                'Microsoft Entra token endpoint returned a temporary error.',
                $response->statusCode,
                $this->retryAfter($response)
            );
        }

        if ($response->statusCode < 200 || $response->statusCode >= 300) {
            throw new TeamsIntegrationException('Microsoft Entra bot authentication failed.');
        }

        $payload = $response->json();
        $token = trim((string) ($payload['access_token'] ?? ''));
        $expiresIn = (int) ($payload['expires_in'] ?? 0);
        if ($token === '' || $expiresIn < 60) {
            throw new TeamsIntegrationException('Microsoft Entra returned an incomplete bot token.');
        }

        $this->accessToken = $token;
        $this->expiresAt = time() + max(30, $expiresIn - 60);

        return $token;
    }

    private function retryAfter(HttpResponse $response): ?int
    {
        $value = $response->headers['retry-after'] ?? null;
        if (is_numeric($value)) {
            return max(1, min((int) $value, 3600));
        }

        $timestamp = is_string($value) ? strtotime($value) : false;
        return $timestamp !== false ? max(1, min($timestamp - time(), 3600)) : null;
    }
}
