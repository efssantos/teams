<?php

/**
 * GLPI Microsoft Teams Integration plugin.
 *
 * The plugin keeps the GLPI integration layer independent from Microsoft
 * client implementations. Provider adapters are isolated under src/Api and
 * are registered here without changing GLPI core classes.
 */

define('PLUGIN_GLPIMSTEAMS_VERSION', '0.5.0');
define('PLUGIN_GLPIMSTEAMS_MIN_GLPI', '11.0.0');
define('PLUGIN_GLPIMSTEAMS_DIR', __DIR__);

require_once PLUGIN_GLPIMSTEAMS_DIR . '/src/Exception/TeamsIntegrationException.php';
require_once PLUGIN_GLPIMSTEAMS_DIR . '/src/Exception/GlpiApiException.php';
require_once PLUGIN_GLPIMSTEAMS_DIR . '/src/Service/ConfigurationService.php';
require_once PLUGIN_GLPIMSTEAMS_DIR . '/src/Service/LoggingService.php';
require_once PLUGIN_GLPIMSTEAMS_DIR . '/src/Service/OutboxService.php';
require_once PLUGIN_GLPIMSTEAMS_DIR . '/src/Service/EventStoreService.php';
require_once PLUGIN_GLPIMSTEAMS_DIR . '/src/Service/HealthService.php';
require_once PLUGIN_GLPIMSTEAMS_DIR . '/src/PluginGlpimsteamsPluginCronTask.php';
require_once PLUGIN_GLPIMSTEAMS_DIR . '/src/Api/HttpResponse.php';
require_once PLUGIN_GLPIMSTEAMS_DIR . '/src/Api/HttpClientInterface.php';
require_once PLUGIN_GLPIMSTEAMS_DIR . '/src/Api/CurlHttpClient.php';
require_once PLUGIN_GLPIMSTEAMS_DIR . '/src/Api/GlpiOAuthClient.php';
require_once PLUGIN_GLPIMSTEAMS_DIR . '/src/Api/GlpiClient.php';
require_once PLUGIN_GLPIMSTEAMS_DIR . '/src/Authentication/OAuthStateStore.php';
require_once PLUGIN_GLPIMSTEAMS_DIR . '/src/Authentication/TokenStorageService.php';
require_once PLUGIN_GLPIMSTEAMS_DIR . '/src/Authentication/OAuthService.php';
require_once PLUGIN_GLPIMSTEAMS_DIR . '/src/Exception/ProviderHttpException.php';
require_once PLUGIN_GLPIMSTEAMS_DIR . '/src/Api/TeamsAccessTokenProvider.php';
require_once PLUGIN_GLPIMSTEAMS_DIR . '/src/Api/TeamsBotClient.php';
require_once PLUGIN_GLPIMSTEAMS_DIR . '/src/Service/ContentSanitizer.php';
require_once PLUGIN_GLPIMSTEAMS_DIR . '/src/Service/TeamsRouteService.php';
require_once PLUGIN_GLPIMSTEAMS_DIR . '/src/Service/TeamsMessageService.php';
require_once PLUGIN_GLPIMSTEAMS_DIR . '/src/Service/UserMappingService.php';
require_once PLUGIN_GLPIMSTEAMS_DIR . '/src/Service/TeamsCommandParser.php';
require_once PLUGIN_GLPIMSTEAMS_DIR . '/src/Service/TeamsCommandService.php';
require_once PLUGIN_GLPIMSTEAMS_DIR . '/src/Security/BotFrameworkTokenValidator.php';
require_once PLUGIN_GLPIMSTEAMS_DIR . '/src/Controller/TeamsWebhookController.php';
require_once PLUGIN_GLPIMSTEAMS_DIR . '/src/Service/ServiceFactory.php';
require_once PLUGIN_GLPIMSTEAMS_DIR . '/src/Service/NotificationService.php';

function plugin_init_glpimsteams(): void
{
    global $PLUGIN_HOOKS;

    $PLUGIN_HOOKS['csrf_compliant']['glpimsteams'] = true;

    // GLPI encrypts values registered here through its GLPIKey mechanism.
    $PLUGIN_HOOKS['secured_configs']['glpimsteams'] = [
        'client_secret',
        'bot_app_secret',
        'glpi_api_client_secret',
    ];

    // Reserved for the per-user OAuth token store added in the authentication
    // stage. They are protected when that store is used.
    $PLUGIN_HOOKS['secured_fields']['glpimsteams'] = [
        'glpi_plugin_glpimsteams_user_links.access_token',
        'glpi_plugin_glpimsteams_user_links.refresh_token',
    ];

    if (!Plugin::isPluginActive('glpimsteams')) {
        return;
    }

    $PLUGIN_HOOKS['config_page']['glpimsteams'] = 'front/config.form.php';
    $PLUGIN_HOOKS['item_add']['glpimsteams'] = [
        'Ticket' => 'plugin_glpimsteams_ticket_added',
        'ITILFollowup' => 'plugin_glpimsteams_followup_added',
        'ITILSolution' => 'plugin_glpimsteams_solution_added',
    ];
    $PLUGIN_HOOKS['item_update']['glpimsteams'] = [
        'Ticket' => 'plugin_glpimsteams_ticket_updated',
    ];
    Plugin::registerClass('PluginGlpimsteamsPluginCronTask');
}

function plugin_version_glpimsteams(): array
{
    return [
        'name'         => 'GLPI Microsoft Teams Integration',
        'version'      => PLUGIN_GLPIMSTEAMS_VERSION,
        'author'       => 'GLPI Microsoft Teams Integration contributors',
        'license'      => 'GPL-3.0-or-later',
        'homepage'     => '',
        'requirements' => [
            'glpi' => [
                'min' => PLUGIN_GLPIMSTEAMS_MIN_GLPI,
            ],
        ],
    ];
}

function plugin_glpimsteams_check_prerequisites(): bool
{
    if (version_compare(PHP_VERSION, '8.2.0', '<')) {
        echo 'GLPI Microsoft Teams Integration requires PHP 8.2 or newer.';
        return false;
    }

    return true;
}

function plugin_glpimsteams_check_config($verbose = false): bool
{
    if ($verbose) {
        echo '<br>GLPI Microsoft Teams Integration: configuration is available in Setup &gt; Plugins.';
    }

    return true;
}
