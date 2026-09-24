<?php

namespace GlpiPlugin\Glpimsteams\Tests\Unit;

use GlpiPlugin\Glpimsteams\Api\HttpClientInterface;
use GlpiPlugin\Glpimsteams\Api\HttpResponse;
use GlpiPlugin\Glpimsteams\Security\BotFrameworkTokenValidator;
use GlpiPlugin\Glpimsteams\Service\ConfigurationService;
use GlpiPlugin\Glpimsteams\Exception\TeamsIntegrationException;
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
