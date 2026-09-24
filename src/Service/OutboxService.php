<?php

namespace GlpiPlugin\Glpimsteams\Service;

use GlpiPlugin\Glpimsteams\Exception\ProviderHttpException;
use GlpiPlugin\Glpimsteams\Exception\TeamsIntegrationException;

final class OutboxService
{
    private const TABLE = 'glpi_plugin_glpimsteams_outbox';
    private const MAX_ATTEMPTS = 5;

    public function __construct(
        private readonly TeamsMessageService $messageService,
        private readonly LoggingService $logger
    ) {
    }

    public function createEventKey(string $eventType, string $eventId): string
    {
        $eventType = trim($eventType);
        $eventId = trim($eventId);

        if ($eventType === '' || $eventId === '') {
            throw new TeamsIntegrationException('eventType and eventId are required.');
        }

        return hash('sha256', $eventType . ':' . $eventId);
    }

    public function enqueue(string $eventType, string $eventId, array $payload, ?int $routeId = null): string
    {
        global $DB;

        $eventKey = $this->createEventKey($eventType, $eventId);
        $routeId ??= $this->routeForEntity($payload);
        $existing = $DB->request([
            'FROM' => self::TABLE,
            'WHERE' => ['event_key' => $eventKey],
            'LIMIT' => 1,
        ])->current();
        if (is_array($existing) && !empty($existing['id'])) {
            return $eventKey;
        }

        $now = date('Y-m-d H:i:s');
        try {
            $DB->insert(self::TABLE, [
                'event_key' => $eventKey,
                'route_id' => $routeId,
                'event_type' => $eventType,
                'payload' => json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                'status' => 'pending',
                'attempts' => 0,
                'date_creation' => $now,
                'date_mod' => $now,
            ]);
        } catch (\Throwable $exception) {
            // The unique key is the final concurrency guard when two GLPI
            // workers enqueue the same event at the same time.
            $existing = $DB->request([
                'FROM' => self::TABLE,
                'WHERE' => ['event_key' => $eventKey],
                'LIMIT' => 1,
            ])->current();
            if (!is_array($existing) || empty($existing['id'])) {
                throw $exception;
            }

            return $eventKey;
        }

        $this->logger->info('Teams notification queued', [
            'event_type' => $eventType,
            'event_key' => $eventKey,
            'route_id' => $routeId,
        ]);

        return $eventKey;
    }

    private function routeForEntity(array $payload): ?int
    {
        global $DB;

        if (!array_key_exists('entity_id', $payload)) {
            return null;
        }
        $entityId = (int) $payload['entity_id'];
        if ($entityId < 0) {
            return null;
        }

        $route = $DB->request([
            'FROM' => 'glpi_plugin_glpimsteams_routes',
            'WHERE' => [
                'entities_id' => $entityId,
                'is_active' => 1,
            ],
            'ORDER' => 'id ASC',
            'LIMIT' => 1,
        ])->current();

        return is_array($route) && !empty($route['id']) ? (int) $route['id'] : null;
    }

    public function process(): int
    {
        global $DB;

        $now = date('Y-m-d H:i:s');
        $iterator = $DB->request([
            'FROM' => self::TABLE,
            'WHERE' => [
                'status' => 'pending',
                'OR' => [
                    ['next_attempt_at' => null],
                    ['next_attempt_at' => ['<=', $now]],
                ],
            ],
            'ORDER' => 'id ASC',
            'LIMIT' => 25,
        ]);

        $processed = 0;
        foreach ($iterator as $row) {
            if (!$this->lock($row)) {
                continue;
            }

            $processed++;
            $this->processRow($row);
        }

        return $processed;
    }

    private function lock(array $row): bool
    {
        global $DB;

        $attempts = (int) ($row['attempts'] ?? 0) + 1;
        $updated = $DB->update(self::TABLE, [
            'status' => 'processing',
            'attempts' => $attempts,
            'locked_at' => date('Y-m-d H:i:s'),
            'date_mod' => date('Y-m-d H:i:s'),
        ], [
            'id' => (int) $row['id'],
            'status' => 'pending',
        ]);

        return $updated && $DB->affectedRows() === 1;
    }

    private function processRow(array $row): void
    {
        global $DB;

        try {
            $payload = json_decode((string) $row['payload'], true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($payload)) {
                throw new TeamsIntegrationException('Outbox payload is invalid.');
            }

            $activityId = $this->messageService->sendTicketEvent(
                $payload,
                !empty($row['route_id']) ? (int) $row['route_id'] : null
            );

            $DB->update(self::TABLE, [
                'status' => 'sent',
                'locked_at' => null,
                'next_attempt_at' => null,
                'last_http_status' => null,
                'last_error' => null,
                'date_mod' => date('Y-m-d H:i:s'),
            ], ['id' => (int) $row['id']]);

            $this->logger->info('Teams notification sent', [
                'event_key' => $row['event_key'],
                'activity_id_present' => $activityId !== '',
            ]);
        } catch (ProviderHttpException $exception) {
            $this->handleProviderFailure($row, $exception);
        } catch (\Throwable $exception) {
            $DB->update(self::TABLE, [
                'status' => 'failed',
                'locked_at' => null,
                'last_error' => 'permanent_failure',
                'date_mod' => date('Y-m-d H:i:s'),
            ], ['id' => (int) $row['id']]);

            $this->logger->error('Teams notification failed permanently', [
                'event_key' => $row['event_key'],
                'error_type' => get_class($exception),
            ]);
        }
    }

    private function handleProviderFailure(array $row, ProviderHttpException $exception): void
    {
        global $DB;

        $attempts = (int) ($row['attempts'] ?? 0);
        $canRetry = $attempts < self::MAX_ATTEMPTS;
        if (!$canRetry) {
            $DB->update(self::TABLE, [
                'status' => 'failed',
                'locked_at' => null,
                'last_http_status' => $exception->statusCode,
                'last_error' => 'retry_limit_reached',
                'date_mod' => date('Y-m-d H:i:s'),
            ], ['id' => (int) $row['id']]);
            $this->logger->error('Teams notification retry limit reached', [
                'event_key' => $row['event_key'],
                'http_status' => $exception->statusCode,
            ]);
            return;
        }

        $delay = $exception->retryAfter ?? min(3600, 30 * (2 ** max(0, $attempts - 1)));
        $nextAttempt = date('Y-m-d H:i:s', time() + max(1, $delay));
        $DB->update(self::TABLE, [
            'status' => 'pending',
            'locked_at' => null,
            'next_attempt_at' => $nextAttempt,
            'last_http_status' => $exception->statusCode,
            'last_error' => 'temporary_provider_failure',
            'date_mod' => date('Y-m-d H:i:s'),
        ], ['id' => (int) $row['id']]);

        $this->logger->warning('Teams notification retry scheduled', [
            'event_key' => $row['event_key'],
            'http_status' => $exception->statusCode,
            'attempts' => $attempts,
            'next_attempt_at' => $nextAttempt,
        ]);
    }
}
