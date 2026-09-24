<?php

namespace GlpiPlugin\Glpimsteams\Service;

use GlpiPlugin\Glpimsteams\Api\CurlHttpClient;
use GlpiPlugin\Glpimsteams\Api\TeamsAccessTokenProvider;
use GlpiPlugin\Glpimsteams\Api\TeamsBotClient;

final class ServiceFactory
{
    public static function logger(): LoggingService
    {
        return new LoggingService();
    }

    public static function outbox(): OutboxService
    {
        $configuration = new ConfigurationService();
        $httpClient = new CurlHttpClient();
        $tokenProvider = new TeamsAccessTokenProvider($configuration, $httpClient);
        $botClient = new TeamsBotClient($tokenProvider, $httpClient);
        $messageService = new TeamsMessageService(
            $configuration,
            new TeamsRouteService($configuration),
            $botClient,
            new ContentSanitizer()
        );

        return new OutboxService($messageService, self::logger());
    }
}
