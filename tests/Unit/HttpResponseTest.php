<?php

namespace GlpiPlugin\Glpimsteams\Tests\Unit;

use GlpiPlugin\Glpimsteams\Api\HttpResponse;
use GlpiPlugin\Glpimsteams\Exception\TeamsIntegrationException;
use PHPUnit\Framework\TestCase;

final class HttpResponseTest extends TestCase
{
    public function testInvalidJsonIsRejectedWithoutLeakingProviderBody(): void
    {
        $response = new HttpResponse(502, 'client_secret=hidden');

        $this->expectException(TeamsIntegrationException::class);
        $this->expectExceptionMessage('invalid JSON');
        $response->json();
    }
}
