<?php

namespace GlpiPlugin\Glpimsteams\Api;

use GlpiPlugin\Glpimsteams\Exception\GlpiApiException;
use GlpiPlugin\Glpimsteams\Service\ConfigurationService;

final class GlpiClient
{
    private const API_PREFIX = '/api.php/v2';

    public function __construct(
        private readonly ConfigurationService $configuration,
        private readonly HttpClientInterface $httpClient
    ) {
    }

    public function createTicket(
        string $accessToken,
        string $title,
        string $content,
        ?int $categoryId = null,
        ?int $priority = null,
        ?int $entityId = null
    ): array {
        $body = [
            'name' => $title,
            'content' => $content,
        ];
        if ($categoryId !== null && $categoryId > 0) {
            $body['category'] = ['id' => $categoryId];
        }
        if ($priority !== null && $priority >= 1 && $priority <= 5) {
            $body['priority'] = $priority;
        }
        if ($entityId !== null && $entityId >= 0) {
            $body['entity'] = $entityId;
        }

        return $this->request('POST', '/Assistance/Ticket', $accessToken, $body);
    }

    public function getTicket(string $accessToken, int $ticketId): array
    {
        return $this->request('GET', '/Assistance/Ticket/' . $this->id($ticketId), $accessToken);
    }

    public function listTickets(string $accessToken, int $limit = 100): array
    {
        $limit = max(1, min(100, $limit));
        $result = $this->request('GET', '/Assistance/Ticket?limit=' . $limit, $accessToken);
        if (isset($result['items']) && is_array($result['items'])) {
            return array_values($result['items']);
        }

        return array_is_list($result) ? $result : [];
    }

    public function addFollowup(string $accessToken, int $ticketId, string $content, bool $private = false): array
    {
        return $this->request(
            'POST',
            '/Assistance/Ticket/' . $this->id($ticketId) . '/Timeline/Followup',
            $accessToken,
            [
                'content' => $content,
                'is_private' => $private,
            ]
        );
    }

    public function updateStatus(string $accessToken, int $ticketId, int $statusId): array
    {
        return $this->request(
            'PATCH',
            '/Assistance/Ticket/' . $this->id($ticketId),
            $accessToken,
            ['status' => ['id' => $statusId]]
        );
    }

    public function refreshToken(array $configuration, string $refreshToken): array
    {
        $baseUrl = rtrim((string) ($configuration['base_url'] ?? ''), '/');
        $clientId = trim((string) ($configuration['glpi_api_client_id'] ?? ''));
        $clientSecret = (string) ($configuration['glpi_api_client_secret'] ?? '');
        if ($baseUrl === '' || $clientId === '' || $clientSecret === '' || $refreshToken === '') {
            throw new GlpiApiException('GLPI OAuth refresh configuration is incomplete.');
        }

        $response = $this->httpClient->request('POST', $baseUrl . '/api.php/token', [], [
            'grant_type' => 'refresh_token',
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
            'refresh_token' => $refreshToken,
        ]);
        if ($response->statusCode < 200 || $response->statusCode >= 300) {
            throw new GlpiApiException('GLPI OAuth refresh failed.', $response->statusCode);
        }

        $payload = $response->json();
        if (empty($payload['access_token']) || empty($payload['expires_in'])) {
            throw new GlpiApiException('GLPI OAuth refresh response is incomplete.', $response->statusCode);
        }

        return $payload;
    }

    private function request(string $method, string $path, string $accessToken, array|string|null $body = null): array
    {
        $configuration = $this->configuration->get();
        $baseUrl = rtrim((string) ($configuration['base_url'] ?? ''), '/');
        if ($baseUrl === '') {
            throw new GlpiApiException('GLPI base URL is not configured.');
        }
        if ($accessToken === '') {
            throw new GlpiApiException('A GLPI OAuth access token is required.');
        }

        $url = $baseUrl . self::API_PREFIX . $path;
        $headers = [
            'Authorization: Bearer ' . $accessToken,
            'Accept: application/json',
        ];
        if ($body !== null) {
            $headers[] = 'Content-Type: application/json';
            $body = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        }

        $response = $this->httpClient->request($method, $url, $headers, $body);
        if ($response->statusCode === 429 || $response->statusCode >= 500) {
            throw new GlpiApiException('GLPI API temporary failure.', $response->statusCode, $this->retryAfter($response));
        }
        if ($response->statusCode < 200 || $response->statusCode >= 300) {
            throw new GlpiApiException('GLPI API request was rejected.', $response->statusCode);
        }

        return trim($response->body) === '' ? [] : $response->json();
    }

    private function id(int $id): string
    {
        if ($id <= 0) {
            throw new GlpiApiException('A positive GLPI item ID is required.');
        }

        return (string) $id;
    }

    private function retryAfter(HttpResponse $response): ?int
    {
        $value = $response->headers['retry-after'] ?? null;
        if ($value === null || !is_numeric($value)) {
            return null;
        }

        return max(1, (int) $value);
    }
}
