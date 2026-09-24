<?php

use GlpiPlugin\Glpimsteams\Api\CurlHttpClient;
use GlpiPlugin\Glpimsteams\Api\GlpiClient;
use GlpiPlugin\Glpimsteams\Api\GlpiOAuthClient;
use GlpiPlugin\Glpimsteams\Api\TeamsAccessTokenProvider;
use GlpiPlugin\Glpimsteams\Api\TeamsBotClient;
use GlpiPlugin\Glpimsteams\Authentication\OAuthService;
use GlpiPlugin\Glpimsteams\Authentication\OAuthStateStore;
use GlpiPlugin\Glpimsteams\Authentication\TokenStorageService;
use GlpiPlugin\Glpimsteams\Controller\TeamsWebhookController;
use GlpiPlugin\Glpimsteams\Security\BotFrameworkTokenValidator;
use GlpiPlugin\Glpimsteams\Service\ConfigurationService;
use GlpiPlugin\Glpimsteams\Service\ContentSanitizer;
use GlpiPlugin\Glpimsteams\Service\EventStoreService;
use GlpiPlugin\Glpimsteams\Service\LoggingService;
use GlpiPlugin\Glpimsteams\Service\TeamsCommandService;
use GlpiPlugin\Glpimsteams\Service\TeamsCommandParser;
use GlpiPlugin\Glpimsteams\Service\UserMappingService;

include '../../../inc/includes.php';

$configuration = new ConfigurationService();
$httpClient = new CurlHttpClient();
$tokenProvider = new TeamsAccessTokenProvider($configuration, $httpClient);
$botClient = new TeamsBotClient($tokenProvider, $httpClient);
$glpiClient = new GlpiClient($configuration, $httpClient);
$commandService = new TeamsCommandService(
    $glpiClient,
    new UserMappingService(new TokenStorageService(), $glpiClient, $configuration),
    $botClient,
    new ContentSanitizer(),
    new OAuthService(
        $configuration,
        new GlpiOAuthClient($httpClient),
        new OAuthStateStore(),
        new TokenStorageService(),
        new LoggingService()
    ),
    new TeamsCommandParser(new ContentSanitizer())
);
$controller = new TeamsWebhookController(
    new BotFrameworkTokenValidator($configuration, $httpClient),
    new EventStoreService(),
    $commandService,
    new LoggingService()
);

$headers = function_exists('getallheaders') ? getallheaders() : [];
$authorization = (string) ($headers['Authorization'] ?? $headers['authorization'] ?? $_SERVER['HTTP_AUTHORIZATION'] ?? '');
$body = file_get_contents('php://input');
[$status, $payload] = $controller->handle($authorization, is_string($body) ? $body : '');

http_response_code($status);
header('Content-Type: application/json; charset=utf-8');
echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
