<?php

namespace GlpiPlugin\Teams\Service;

final class EventStoreService
{
    private const TABLE = 'glpi_plugin_teams_events';

    public function claim(string $provider, string $externalId, string $eventType, array $payload): bool
    {
        global $DB;

        $provider = trim($provider);
        $externalId = trim($externalId);
        if ($provider === '' || $externalId === '') {
            return false;
        }
        $eventKey = hash('sha256', $provider . ':' . $externalId);
        $record = [
            'event_key' => $eventKey,
            'provider' => $provider,
            'external_id' => substr($externalId, 0, 255),
            'event_type' => substr(trim($eventType), 0, 128),
            'payload_hash' => hash('sha256', json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)),
            'date_creation' => date('Y-m-d H:i:s'),
        ];
        try {
            $DB->insert(self::TABLE, $record);
            return true;
        } catch (\Throwable $exception) {
            $existing = $DB->request([
                'FROM' => self::TABLE,
                'WHERE' => ['event_key' => $eventKey],
                'LIMIT' => 1,
            ])->current();
            if (is_array($existing) && !empty($existing['id'])) {
                return false;
            }

            throw $exception;
        }
    }

    public function markProcessed(string $provider, string $externalId): void
    {
        global $DB;
        $DB->update(self::TABLE, ['processed_at' => date('Y-m-d H:i:s')], [
            'event_key' => hash('sha256', trim($provider) . ':' . trim($externalId)),
        ]);
    }
}
