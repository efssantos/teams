<?php

namespace GlpiPlugin\Teams\Authentication;

use GlpiPlugin\Teams\Exception\TeamsIntegrationException;

final class OAuthStateStore
{
    private const TABLE = 'glpi_plugin_teams_oauth_states';
    private const DEFAULT_TTL = 600;

    public function create(array $teamsIdentity, int $ttl = self::DEFAULT_TTL): string
    {
        global $DB;

        $userId = $this->requiredIdentityValue($teamsIdentity, 'user_id');
        $tenantId = $this->requiredIdentityValue($teamsIdentity, 'tenant_id');
        $email = isset($teamsIdentity['email']) ? trim((string) $teamsIdentity['email']) : '';

        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new TeamsIntegrationException('The Teams identity contains an invalid email address.');
        }

        $ttl = max(60, min($ttl, 900));
        $state = $this->base64Url(random_bytes(32));
        $now = date('Y-m-d H:i:s');
        $expiresAt = date('Y-m-d H:i:s', time() + $ttl);

        $DB->delete(self::TABLE, ['expires_at' => ['<', $now]]);
        $DB->insert(self::TABLE, [
            'state_hash'       => hash('sha256', $state),
            'teams_user_id'    => $userId,
            'teams_email'      => $email !== '' ? $email : null,
            'teams_tenant_id'  => $tenantId,
            'expires_at'       => $expiresAt,
            'date_creation'   => $now,
        ]);

        return $state;
    }

    public function consume(string $state): array
    {
        global $DB;

        if (!preg_match('/^[A-Za-z0-9_-]{40,128}$/', $state)) {
            throw new TeamsIntegrationException('Invalid OAuth state.');
        }

        $now = date('Y-m-d H:i:s');
        $iterator = $DB->request([
            'FROM'  => self::TABLE,
            'WHERE' => [
                'state_hash' => hash('sha256', $state),
            ],
            'LIMIT' => 1,
        ]);
        $record = $iterator->current();

        if (!is_array($record) || empty($record['id'])) {
            throw new TeamsIntegrationException('OAuth state was not found or has already been used.');
        }

        if (!empty($record['consumed_at']) || empty($record['expires_at']) || $record['expires_at'] < $now) {
            throw new TeamsIntegrationException('OAuth state expired or already consumed.');
        }

        $transactionStarted = false;
        try {
            $DB->beginTransaction();
            $transactionStarted = true;
            $DB->update(self::TABLE, ['consumed_at' => $now], [
                'id' => (int) $record['id'],
                'consumed_at' => null,
            ]);

            if ($DB->affectedRows() !== 1) {
                $DB->rollBack();
                $transactionStarted = false;
                throw new TeamsIntegrationException('OAuth state was already consumed.');
            }

            $DB->commit();
            $transactionStarted = false;
        } catch (\Throwable $exception) {
            if ($transactionStarted) {
                $DB->rollBack();
            }
            throw $exception;
        }

        return [
            'teams_user_id'   => (string) $record['teams_user_id'],
            'teams_email'     => (string) ($record['teams_email'] ?? ''),
            'teams_tenant_id' => (string) $record['teams_tenant_id'],
        ];
    }

    private function requiredIdentityValue(array $identity, string $key): string
    {
        $value = trim((string) ($identity[$key] ?? ''));
        if ($value === '' || strlen($value) > 191 || !preg_match('/^[A-Za-z0-9._:@-]+$/', $value)) {
            throw new TeamsIntegrationException('Invalid Teams identity value.');
        }

        return $value;
    }

    private function base64Url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
