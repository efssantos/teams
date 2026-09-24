<?php

use GlpiPlugin\Teams\Service\ConfigurationService;
use GlpiPlugin\Teams\Service\ContentSanitizer;
use GlpiPlugin\Teams\Service\NotificationService;
use GlpiPlugin\Teams\Service\ServiceFactory;

function plugin_teams_ticket_added(object $ticket): void
{
    plugin_teams_handle_notification(static function () use ($ticket): void {
        $service = new NotificationService(
            new ConfigurationService(),
            ServiceFactory::outbox(),
            new ContentSanitizer()
        );
        $service->ticketCreated($ticket);
    });
}

function plugin_teams_ticket_updated(object $ticket): void
{
    plugin_teams_handle_notification(static function () use ($ticket): void {
        $service = new NotificationService(
            new ConfigurationService(),
            ServiceFactory::outbox(),
            new ContentSanitizer()
        );
        $service->ticketUpdated($ticket);
    });
}

function plugin_teams_followup_added(object $followup): void
{
    plugin_teams_handle_notification(static function () use ($followup): void {
        $service = new NotificationService(
            new ConfigurationService(),
            ServiceFactory::outbox(),
            new ContentSanitizer()
        );
        $service->followupCreated($followup);
    });
}

function plugin_teams_solution_added(object $solution): void
{
    plugin_teams_handle_notification(static function () use ($solution): void {
        $service = new NotificationService(
            new ConfigurationService(),
            ServiceFactory::outbox(),
            new ContentSanitizer()
        );
        $service->solutionCreated($solution);
    });
}

function plugin_teams_handle_notification(Closure $callback): void
{
    try {
        $callback();
    } catch (Throwable $exception) {
        ServiceFactory::logger()->error('Unable to queue Teams notification', [
            'error_type' => get_class($exception),
        ]);
    }
}

function plugin_teams_install(): bool
{
    global $DB;

    $migration = new Migration(PLUGIN_TEAMS_VERSION);

    $tables = [
        'glpi_plugin_teams_routes' => "
            CREATE TABLE IF NOT EXISTS `glpi_plugin_teams_routes` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `entities_id` INT UNSIGNED NOT NULL DEFAULT 0,
                `name` VARCHAR(191) NOT NULL,
                `team_id` VARCHAR(191) NOT NULL,
                `channel_id` VARCHAR(191) NOT NULL,
                `conversation_id` VARCHAR(255) DEFAULT NULL,
                `service_url` VARCHAR(512) DEFAULT NULL,
                `notification_flags` LONGTEXT,
                `is_active` TINYINT NOT NULL DEFAULT 1,
                `date_creation` TIMESTAMP NULL DEFAULT NULL,
                `date_mod` TIMESTAMP NULL DEFAULT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uniq_route_entity_name` (`entities_id`, `name`),
                KEY `entities_id` (`entities_id`),
                KEY `is_active` (`is_active`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC
        ",
        'glpi_plugin_teams_bot_keys' => "
            CREATE TABLE IF NOT EXISTS `glpi_plugin_teams_bot_keys` (
                `id` TINYINT UNSIGNED NOT NULL,
                `jwks` LONGTEXT NOT NULL,
                `fetched_at` DATETIME NOT NULL,
                PRIMARY KEY (`id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC
        ",
        'glpi_plugin_teams_user_links' => "
            CREATE TABLE IF NOT EXISTS `glpi_plugin_teams_user_links` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `users_id` INT UNSIGNED NOT NULL,
                `tenant_id` VARCHAR(191) NOT NULL,
                `entra_object_id` VARCHAR(191) DEFAULT NULL,
                `entra_email` VARCHAR(255) DEFAULT NULL,
                `access_token` LONGTEXT,
                `refresh_token` LONGTEXT,
                `expires_at` DATETIME DEFAULT NULL,
                `is_active` TINYINT NOT NULL DEFAULT 1,
                `date_creation` TIMESTAMP NULL DEFAULT NULL,
                `date_mod` TIMESTAMP NULL DEFAULT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uniq_user_tenant` (`users_id`, `tenant_id`),
                KEY `entra_email` (`entra_email`),
                KEY `is_active` (`is_active`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC
        ",
        'glpi_plugin_teams_oauth_states' => "
            CREATE TABLE IF NOT EXISTS `glpi_plugin_teams_oauth_states` (
                `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                `state_hash` CHAR(64) NOT NULL,
                `teams_user_id` VARCHAR(191) NOT NULL,
                `teams_email` VARCHAR(255) DEFAULT NULL,
                `teams_tenant_id` VARCHAR(191) NOT NULL,
                `expires_at` DATETIME NOT NULL,
                `consumed_at` DATETIME DEFAULT NULL,
                `date_creation` TIMESTAMP NULL DEFAULT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uniq_state_hash` (`state_hash`),
                KEY `expires_at` (`expires_at`),
                KEY `teams_user_id` (`teams_user_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC
        ",
        'glpi_plugin_teams_events' => "
            CREATE TABLE IF NOT EXISTS `glpi_plugin_teams_events` (
                `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                `event_key` VARCHAR(191) NOT NULL,
                `provider` VARCHAR(64) NOT NULL,
                `external_id` VARCHAR(255) DEFAULT NULL,
                `event_type` VARCHAR(128) NOT NULL,
                `payload_hash` CHAR(64) DEFAULT NULL,
                `processed_at` DATETIME DEFAULT NULL,
                `date_creation` TIMESTAMP NULL DEFAULT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uniq_event_key` (`event_key`),
                KEY `external_id` (`external_id`),
                KEY `event_type` (`event_type`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC
        ",
        'glpi_plugin_teams_outbox' => "
            CREATE TABLE IF NOT EXISTS `glpi_plugin_teams_outbox` (
                `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                `event_key` VARCHAR(191) NOT NULL,
                `route_id` INT UNSIGNED DEFAULT NULL,
                `event_type` VARCHAR(128) NOT NULL,
                `payload` LONGTEXT NOT NULL,
                `status` VARCHAR(32) NOT NULL DEFAULT 'pending',
                `attempts` INT UNSIGNED NOT NULL DEFAULT 0,
                `next_attempt_at` DATETIME DEFAULT NULL,
                `locked_at` DATETIME DEFAULT NULL,
                `last_http_status` SMALLINT UNSIGNED DEFAULT NULL,
                `last_error` TEXT,
                `date_creation` TIMESTAMP NULL DEFAULT NULL,
                `date_mod` TIMESTAMP NULL DEFAULT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uniq_outbox_event_key` (`event_key`),
                KEY `status_next_attempt` (`status`, `next_attempt_at`),
                KEY `route_id` (`route_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC
        ",
        'glpi_plugin_teams_logs' => "
            CREATE TABLE IF NOT EXISTS `glpi_plugin_teams_logs` (
                `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                `level` VARCHAR(16) NOT NULL,
                `request_id` VARCHAR(64) NOT NULL,
                `event_type` VARCHAR(128) DEFAULT NULL,
                `tickets_id` INT UNSIGNED DEFAULT NULL,
                `http_status` SMALLINT UNSIGNED DEFAULT NULL,
                `message` TEXT NOT NULL,
                `context` LONGTEXT,
                `date_creation` TIMESTAMP NULL DEFAULT NULL,
                PRIMARY KEY (`id`),
                KEY `request_id` (`request_id`),
                KEY `event_type` (`event_type`),
                KEY `tickets_id` (`tickets_id`),
                KEY `level` (`level`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC
        ",
    ];

    foreach ($tables as $table => $query) {
        if (!$DB->tableExists($table)) {
            $DB->doQuery($query);
        }
    }

    $migration->executeMigration();

    $existingConfiguration = Config::getConfigurationValues(
        ConfigurationService::CONTEXT,
        array_keys(ConfigurationService::defaults())
    );
    $missingConfiguration = array_diff_key(ConfigurationService::defaults(), $existingConfiguration);
    if ($missingConfiguration !== []) {
        Config::setConfigurationValues(ConfigurationService::CONTEXT, $missingConfiguration);
    }

    CronTask::register(
        'PluginTeamsPluginCronTask',
        'processOutbox',
        60,
        [
            'comment' => 'Process GLPI Microsoft Teams Integration outbox',
        ]
    );

    return true;
}

function plugin_teams_uninstall(): bool
{
    global $DB;

    CronTask::unregister('teams');

    Config::deleteConfigurationValues(
        ConfigurationService::CONTEXT,
        array_keys(ConfigurationService::defaults())
    );

    $tables = [
        'glpi_plugin_teams_routes',
        'glpi_plugin_teams_bot_keys',
        'glpi_plugin_teams_user_links',
        'glpi_plugin_teams_oauth_states',
        'glpi_plugin_teams_events',
        'glpi_plugin_teams_outbox',
        'glpi_plugin_teams_logs',
    ];

    foreach ($tables as $table) {
        if ($DB->tableExists($table)) {
            $DB->dropTable($table);
        }
    }

    return true;
}
