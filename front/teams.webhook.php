<?php

use GlpiPlugin\Teams\Api\CurlHttpClient;
use GlpiPlugin\Teams\Api\GlpiClient;
use GlpiPlugin\Teams\Api\GlpiOAuthClient;
use GlpiPlugin\Teams\Api\TeamsAccessTokenProvider;
use GlpiPlugin\Teams\Api\TeamsBotClient;
use GlpiPlugin\Teams\Authentication\OAuthService;
use GlpiPlugin\Teams\Authentication\OAuthStateStore;
use GlpiPlugin\Teams\Authentication\TokenStorageService;
use GlpiPlugin\Teams\Controller\TeamsWebhookController;
use GlpiPlugin\Teams\Security\BotFrameworkTokenValidator;
use GlpiPlugin\Teams\Service\ConfigurationService;
use GlpiPlugin\Teams\Service\ContentSanitizer;
use GlpiPlugin\Teams\Service\EventStoreService;
use GlpiPlugin\Teams\Service\LoggingService;
use GlpiPlugin\Teams\Service\TeamsCommandService;
use GlpiPlugin\Teams\Service\TeamsCommandParser;
use GlpiPlugin\Teams\Service\UserMappingService;

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
