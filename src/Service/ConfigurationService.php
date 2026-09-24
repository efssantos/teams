<?php

namespace GlpiPlugin\Glpimsteams\Service;

use Config;

final class ConfigurationService
{
    public const CONTEXT = 'plugin:glpimsteams';

    private const SECRET_FIELDS = [
        'client_secret',
        'bot_app_secret',
        'glpi_api_client_secret',
    ];

    private const BOOLEAN_FIELDS = [
        'enabled',
        'notify_new_ticket',
        'notify_ticket_update',
        'notify_followup',
        'notify_status',
        'notify_priority',
        'notify_assignment',
        'notify_solution',
        'notify_closure',
    ];

    public static function defaults(): array
    {
        return [
            'enabled'                 => 0,
            'tenant_id'               => '',
            'client_id'               => '',
            'client_secret'           => '',
            'bot_app_id'              => '',
            'bot_app_secret'          => '',
            'teams_app_id'            => '',
            'default_team_id'         => '',
            'default_channel_id'      => '',
            'default_conversation_id' => '',
            'default_service_url'    => '',
            'webhook_url'             => '',
            'glpi_redirect_uri'       => '',
            'glpi_api_client_id'     => '',
            'glpi_api_client_secret' => '',
            'glpi_oauth_scope'       => 'api user email',
            'base_url'                => '',
            'notify_new_ticket'       => 1,
            'notify_ticket_update'    => 1,
            'notify_followup'         => 1,
            'notify_status'           => 1,
            'notify_priority'         => 1,
            'notify_assignment'       => 1,
            'notify_solution'         => 1,
            'notify_closure'          => 1,
        ];
    }

    public function get(): array
    {
        return array_replace(
            self::defaults(),
            Config::getConfigurationValues(self::CONTEXT, array_keys(self::defaults()))
        );
    }

    public function getMasked(): array
    {
        $values = $this->get();

        foreach (self::SECRET_FIELDS as $field) {
            if (!empty($values[$field])) {
                $values[$field] = '********';
            }
        }

        return $values;
    }

    public function save(array $input): void
    {
        $values = [];

        foreach (self::defaults() as $field => $default) {
            if (in_array($field, self::BOOLEAN_FIELDS, true)) {
                $values[$field] = !empty($input[$field]) ? 1 : 0;
                continue;
            }

            if (!array_key_exists($field, $input)) {
                continue;
            }

            $value = is_scalar($input[$field]) ? trim((string) $input[$field]) : '';

            // Blank secret inputs mean "keep the current value".
            if (in_array($field, self::SECRET_FIELDS, true) && $value === '') {
                continue;
            }

            $values[$field] = $value;
        }

        if ($values !== []) {
            Config::setConfigurationValues(self::CONTEXT, $values);
        }
    }
}
