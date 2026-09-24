<?php

/**
 * GLPI Microsoft Teams Integration plugin.
 *
 * The plugin keeps the GLPI integration layer independent from Microsoft
 * client implementations. Provider adapters are isolated under src/Api and
 * are registered here without changing GLPI core classes.
 */

define('PLUGIN_TEAMS_VERSION', '0.5.0');
define('PLUGIN_TEAMS_MIN_GLPI', '11.0.0');
define('PLUGIN_TEAMS_DIR', __DIR__);

require_once PLUGIN_TEAMS_DIR . '/src/Exception/TeamsIntegrationException.php';
require_once PLUGIN_TEAMS_DIR . '/src/Exception/GlpiApiException.php';
require_once PLUGIN_TEAMS_DIR . '/src/Service/ConfigurationService.php';
require_once PLUGIN_TEAMS_DIR . '/src/Service/LoggingService.php';
require_once PLUGIN_TEAMS_DIR . '/src/Service/OutboxService.php';
require_once PLUGIN_TEAMS_DIR . '/src/Service/EventStoreService.php';
require_once PLUGIN_TEAMS_DIR . '/src/Service/HealthService.php';
require_once PLUGIN_TEAMS_DIR . '/src/PluginTeamsPluginCronTask.php';
require_once PLUGIN_TEAMS_DIR . '/src/Api/HttpResponse.php';
require_once PLUGIN_TEAMS_DIR . '/src/Api/HttpClientInterface.php';
require_once PLUGIN_TEAMS_DIR . '/src/Api/CurlHttpClient.php';
require_once PLUGIN_TEAMS_DIR . '/src/Api/GlpiOAuthClient.php';
require_once PLUGIN_TEAMS_DIR . '/src/Api/GlpiClient.php';
require_once PLUGIN_TEAMS_DIR . '/src/Authentication/OAuthStateStore.php';
require_once PLUGIN_TEAMS_DIR . '/src/Authentication/TokenStorageService.php';
require_once PLUGIN_TEAMS_DIR . '/src/Authentication/OAuthService.php';
require_once PLUGIN_TEAMS_DIR . '/src/Exception/ProviderHttpException.php';
require_once PLUGIN_TEAMS_DIR . '/src/Api/TeamsAccessTokenProvider.php';
require_once PLUGIN_TEAMS_DIR . '/src/Api/TeamsBotClient.php';
require_once PLUGIN_TEAMS_DIR . '/src/Service/ContentSanitizer.php';
require_once PLUGIN_TEAMS_DIR . '/src/Service/TeamsRouteService.php';
require_once PLUGIN_TEAMS_DIR . '/src/Service/TeamsMessageService.php';
require_once PLUGIN_TEAMS_DIR . '/src/Service/UserMappingService.php';
require_once PLUGIN_TEAMS_DIR . '/src/Service/TeamsCommandParser.php';
require_once PLUGIN_TEAMS_DIR . '/src/Service/TeamsCommandService.php';
require_once PLUGIN_TEAMS_DIR . '/src/Security/BotFrameworkTokenValidator.php';
require_once PLUGIN_TEAMS_DIR . '/src/Controller/TeamsWebhookController.php';
require_once PLUGIN_TEAMS_DIR . '/src/Service/ServiceFactory.php';
require_once PLUGIN_TEAMS_DIR . '/src/Service/NotificationService.php';

function plugin_init_teams(): void
{
    global $PLUGIN_HOOKS;

    $PLUGIN_HOOKS['csrf_compliant']['teams'] = true;

    // GLPI encrypts values registered here through its GLPIKey mechanism.
    $PLUGIN_HOOKS['secured_configs']['teams'] = [
        'client_secret',
        'bot_app_secret',
        'glpi_api_client_secret',
    ];

    // Reserved for the per-user OAuth token store added in the authentication
    // stage. They are protected when that store is used.
    $PLUGIN_HOOKS['secured_fields']['teams'] = [
        'glpi_plugin_teams_user_links.access_token',
        'glpi_plugin_teams_user_links.refresh_token',
    ];

    if (!Plugin::isPluginActive('teams')) {
        return;
    }

    $PLUGIN_HOOKS['config_page']['teams'] = 'front/config.form.php';
    $PLUGIN_HOOKS['item_add']['teams'] = [
        'Ticket' => 'plugin_teams_ticket_added',
        'ITILFollowup' => 'plugin_teams_followup_added',
        'ITILSolution' => 'plugin_teams_solution_added',
    ];
    $PLUGIN_HOOKS['item_update']['teams'] = [
        'Ticket' => 'plugin_teams_ticket_updated',
    ];
    Plugin::registerClass('PluginTeamsPluginCronTask');
}

function plugin_version_teams(): array
{
    return [
        'name'         => 'GLPI Microsoft Teams Integration',
        'version'      => PLUGIN_TEAMS_VERSION,
        'author'       => 'GLPI Microsoft Teams Integration contributors',
        'license'      => 'GPL-3.0-or-later',
        'homepage'     => '',
        'requirements' => [
            'glpi' => [
                'min' => PLUGIN_TEAMS_MIN_GLPI,
            ],
        ],
    ];
}

function plugin_teams_check_prerequisites(): bool
{
    if (version_compare(PHP_VERSION, '8.2.0', '<')) {
        echo 'GLPI Microsoft Teams Integration requires PHP 8.2 or newer.';
        return false;
    }

    return true;
}

function plugin_teams_check_config($verbose = false): bool
{
    if ($verbose) {
        echo '<br>GLPI Microsoft Teams Integration: configuration is available in Setup &gt; Plugins.';
    }

    return true;
}
