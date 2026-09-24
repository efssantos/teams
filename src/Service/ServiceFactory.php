<?php

namespace GlpiPlugin\Teams\Service;

use GlpiPlugin\Teams\Api\CurlHttpClient;
use GlpiPlugin\Teams\Api\TeamsAccessTokenProvider;
use GlpiPlugin\Teams\Api\TeamsBotClient;

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
