<?php

namespace GlpiPlugin\Teams\Tests\Unit;

use GlpiPlugin\Teams\Api\HttpClientInterface;
use GlpiPlugin\Teams\Api\HttpResponse;
use GlpiPlugin\Teams\Security\BotFrameworkTokenValidator;
use GlpiPlugin\Teams\Service\ConfigurationService;
use GlpiPlugin\Teams\Exception\TeamsIntegrationException;
use PHPUnit\Framework\TestCase;

final class BotFrameworkTokenValidatorTest extends TestCase
{
    public function testMissingBearerTokenIsRejectedBeforeNetworkAccess(): void
    {
        $validator = new BotFrameworkTokenValidator(new ConfigurationService(), new NoopHttpClient());

        $this->expectException(TeamsIntegrationException::class);
        $validator->validate('', []);
    }
}

final class NoopHttpClient implements HttpClientInterface
{
    public function request(string $method, string $url, array $headers = [], array|string|null $body = null): HttpResponse
    {
        throw new \LogicException('Network must not be called for an invalid bearer token.');
    }
}
