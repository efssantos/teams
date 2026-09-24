<?php

namespace GlpiPlugin\Glpimsteams\Api;

use GlpiPlugin\Glpimsteams\Exception\ProviderHttpException;
use GlpiPlugin\Glpimsteams\Exception\TeamsIntegrationException;

final class TeamsBotClient
{
    public function __construct(
        private readonly TeamsAccessTokenProvider $tokenProvider,
        private readonly HttpClientInterface $httpClient
    ) {
    }

    public function sendActivity(string $serviceUrl, string $conversationId, array $activity): string
    {
        $serviceUrl = rtrim(trim($serviceUrl), '/');
        $conversationId = trim($conversationId);
        $parts = parse_url($serviceUrl);
        if (!is_array($parts) || strtolower((string) ($parts['scheme'] ?? '')) !== 'https' || empty($parts['host'])) {
            throw new TeamsIntegrationException('The Teams service URL must use HTTPS.');
        }

        if ($conversationId === '' || strlen($conversationId) > 1024) {
            throw new TeamsIntegrationException('Invalid Teams conversation ID.');
        }

        $json = json_encode($activity, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $url = $serviceUrl . '/v3/conversations/' . rawurlencode($conversationId) . '/activities';
        $accessToken = $this->tokenProvider->getAccessToken();
        try {
            $response = $this->httpClient->request('POST', $url, [
                'Authorization: Bearer ' . $accessToken,
                'Content-Type: application/json',
            ], $json);
        } catch (TeamsIntegrationException $exception) {
            throw new ProviderHttpException('Microsoft Teams Bot Connector could not be reached.', 0, null);
        }

        if ($response->statusCode === 429 || $response->statusCode >= 500) {
            throw new ProviderHttpException(
                'Microsoft Teams Bot Connector returned a temporary error.',
                $response->statusCode,
                $this->retryAfter($response)
            );
        }

        if ($response->statusCode < 200 || $response->statusCode >= 300) {
            throw new TeamsIntegrationException('Microsoft Teams Bot Connector rejected the activity.');
        }

        $payload = $response->body !== '' ? $response->json() : [];
        return trim((string) ($payload['id'] ?? ''));
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
