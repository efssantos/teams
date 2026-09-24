<?php

namespace GlpiPlugin\Glpimsteams\Service;

use GlpiPlugin\Glpimsteams\Exception\TeamsIntegrationException;

final class TeamsRouteService
{
    private const TABLE = 'glpi_plugin_glpimsteams_routes';

    public function __construct(private readonly ConfigurationService $configuration)
    {
    }

    public function getRoute(?int $routeId = null): array
    {
        global $DB;

        if ($routeId !== null && $routeId > 0) {
            $route = $DB->request([
                'FROM' => self::TABLE,
                'WHERE' => ['id' => $routeId, 'is_active' => 1],
                'LIMIT' => 1,
            ])->current();
            if (is_array($route) && !empty($route['id'])) {
                return $route;
            }
        }

        $config = $this->configuration->get();
        $teamId = trim((string) ($config['default_team_id'] ?? ''));
        $channelId = trim((string) ($config['default_channel_id'] ?? ''));
        $conversationId = trim((string) ($config['default_conversation_id'] ?? ''));
        $serviceUrl = trim((string) ($config['default_service_url'] ?? ''));

        if ($teamId === '' || $channelId === '' || $conversationId === '' || $serviceUrl === '') {
            throw new TeamsIntegrationException('No active Teams route with conversation data is configured.');
        }

        return [
            'id' => null,
            'team_id' => $teamId,
            'channel_id' => $channelId,
            'conversation_id' => $conversationId,
            'service_url' => $serviceUrl,
        ];
    }
}
