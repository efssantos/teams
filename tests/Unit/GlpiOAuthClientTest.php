<?php

namespace GlpiPlugin\Glpimsteams\Tests\Unit;

use GlpiPlugin\Glpimsteams\Api\GlpiOAuthClient;
use GlpiPlugin\Glpimsteams\Api\HttpClientInterface;
use GlpiPlugin\Glpimsteams\Api\HttpResponse;
use GlpiPlugin\Glpimsteams\Exception\TeamsIntegrationException;
use PHPUnit\Framework\TestCase;

final class GlpiOAuthClientTest extends TestCase
{
    public function testAuthorizationUrlContainsEncodedSecurityParameters(): void
    {
        $client = new GlpiOAuthClient(new FakeHttpClient());
        $url = $client->buildAuthorizationUrl([
            'base_url' => 'https://glpi.example.test',
            'glpi_api_client_id' => 'client-id',
            'glpi_redirect_uri' => 'https://glpi.example.test/plugins/glpimsteams/front/glpi-oauth.callback.php',
            'glpi_oauth_scope' => 'api user email',
        ], 'random-state-value');

        self::assertStringStartsWith('https://glpi.example.test/api.php/authorize?', $url);
        self::assertStringContainsString('response_type=code', $url);
        self::assertStringContainsString('client_id=client-id', $url);
        self::assertStringContainsString('state=random-state-value', $url);
        self::assertStringContainsString('scope=api%20user%20email', $url);
    }

    public function testTokenExchangeRejectsIncompleteProviderResponse(): void
    {
        $httpClient = new FakeHttpClient(new HttpResponse(200, json_encode([
            'token_type' => 'Bearer',
        ], JSON_THROW_ON_ERROR)));
        $client = new GlpiOAuthClient($httpClient);

        $this->expectException(TeamsIntegrationException::class);
        $client->exchangeAuthorizationCode([
            'base_url' => 'https://glpi.example.test',
            'glpi_api_client_id' => 'client-id',
            'glpi_api_client_secret' => 'client-secret',
            'glpi_redirect_uri' => 'https://glpi.example.test/callback',
        ], 'authorization-code');
    }

    public function testTokenExchangeReturnsValidatedTokenPayload(): void
    {
        $httpClient = new FakeHttpClient(new HttpResponse(200, json_encode([
            'token_type' => 'Bearer',
            'expires_in' => 3600,
            'access_token' => 'access-token',
            'refresh_token' => 'refresh-token',
        ], JSON_THROW_ON_ERROR)));
        $client = new GlpiOAuthClient($httpClient);

        $payload = $client->exchangeAuthorizationCode([
            'base_url' => 'https://glpi.example.test',
            'glpi_api_client_id' => 'client-id',
            'glpi_api_client_secret' => 'client-secret',
            'glpi_redirect_uri' => 'https://glpi.example.test/callback',
        ], 'authorization-code');

        self::assertSame('access-token', $payload['access_token']);
        self::assertSame('authorization-code', $httpClient->form['code']);
        self::assertSame('client-secret', $httpClient->form['client_secret']);
    }
}

final class FakeHttpClient implements HttpClientInterface
{
    public array $form = [];

    public function __construct(private readonly ?HttpResponse $response = null)
    {
    }

    public function request(string $method, string $url, array $headers = [], array|string|null $body = null): HttpResponse
    {
        $this->form = is_array($body) ? $body : [];

        return $this->response ?? new HttpResponse(200, '{}');
    }
}
