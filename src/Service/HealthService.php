<?php

namespace GlpiPlugin\Glpimsteams\Service;

use GlpiPlugin\Glpimsteams\Api\TeamsAccessTokenProvider;
use GlpiPlugin\Glpimsteams\Api\TeamsBotClient;
use GlpiPlugin\Glpimsteams\Exception\ProviderHttpException;

final class HealthService
{
    private const OUTBOX_TABLE = 'glpi_plugin_glpimsteams_outbox';
    private const LOG_TABLE = 'glpi_plugin_glpimsteams_logs';

    public function __construct(
        private readonly ConfigurationService $configuration,
        private readonly TeamsAccessTokenProvider $tokenProvider,
        private readonly TeamsBotClient $botClient,
        private readonly TeamsRouteService $routes,
        private readonly LoggingService $logger
    ) {
    }

    public function testConnection(): void
    {
        $this->tokenProvider->getAccessToken();
        $this->logger->info('Teams connection test succeeded');
    }

    public function sendTestMessage(): void
    {
        $route = $this->routes->getRoute();
        $this->botClient->sendActivity(
            (string) $route['service_url'],
            (string) $route['conversation_id'],
            [
                'type' => 'message',
                'text' => 'Teste de conexão do plugin GLPI Microsoft Teams realizado com sucesso.',
            ]
        );
        $this->logger->info('Teams test message sent');
    }

    public function snapshot(): array
    {
        global $DB;

        $counts = [
            'pending' => 0,
            'processing' => 0,
            'sent' => 0,
            'failed' => 0,
        ];
        foreach (array_keys($counts) as $status) {
            $counts[$status] = $this->countOutbox($status);
        }

        $lastLog = $DB->request([
            'FROM' => self::LOG_TABLE,
            'ORDER' => 'id DESC',
            'LIMIT' => 1,
        ])->current();

        return [
            'configured' => $this->isConfigured(),
            'outbox' => $counts,
            'last_log_level' => is_array($lastLog) ? (string) ($lastLog['level'] ?? '') : '',
            'last_log_message' => is_array($lastLog) ? (string) ($lastLog['message'] ?? '') : '',
            'last_log_date' => is_array($lastLog) ? (string) ($lastLog['date_creation'] ?? '') : '',
        ];
    }

    public function userMessage(\Throwable $exception): string
    {
        if ($exception instanceof ProviderHttpException && $exception->statusCode === 429) {
            return 'O Teams limitou temporariamente as requisições. Tente novamente mais tarde.';
        }

        return 'Não foi possível concluir o teste. Consulte os logs do plugin.';
    }

    private function countOutbox(string $status): int
    {
        global $DB;

        $iterator = $DB->request(
            "SELECT COUNT(*) AS `c` FROM `" . self::OUTBOX_TABLE . "` WHERE `status` = '" . $DB->escape($status) . "'"
        );
        $row = $iterator->current();
        return is_array($row) ? (int) ($row['c'] ?? 0) : 0;
    }

    private function isConfigured(): bool
    {
        $config = $this->configuration->get();
        return !empty($config['tenant_id'])
            && !empty($config['bot_app_id'])
            && !empty($config['bot_app_secret']);
    }
}
