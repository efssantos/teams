<?php

namespace GlpiPlugin\Teams\Authentication;

use GLPIKey;
use GlpiPlugin\Teams\Exception\TeamsIntegrationException;

final class TokenStorageService
{
    private const TABLE = 'glpi_plugin_teams_user_links';

    public function save(int $usersId, array $teamsIdentity, array $tokenPayload): void
    {
        global $DB;

        if ($usersId <= 0) {
            throw new TeamsIntegrationException('A valid GLPI user is required.');
        }

        $tenantId = trim((string) ($teamsIdentity['tenant_id'] ?? $teamsIdentity['teams_tenant_id'] ?? ''));
        $teamsUserId = trim((string) ($teamsIdentity['user_id'] ?? $teamsIdentity['teams_user_id'] ?? ''));
        $email = trim((string) ($teamsIdentity['email'] ?? $teamsIdentity['teams_email'] ?? ''));
        $accessToken = trim((string) ($tokenPayload['access_token'] ?? ''));
        $refreshToken = trim((string) ($tokenPayload['refresh_token'] ?? ''));

        if ($tenantId === '' || $teamsUserId === '' || $accessToken === '') {
            throw new TeamsIntegrationException('The OAuth identity or access token is incomplete.');
        }

        $key = new GLPIKey();
        $encryptedAccessToken = $this->encrypt($key, $accessToken);
        $encryptedRefreshToken = $refreshToken !== '' ? $this->encrypt($key, $refreshToken) : null;
        $expiresIn = max(60, (int) ($tokenPayload['expires_in'] ?? 3600));
        $expiresAt = date('Y-m-d H:i:s', time() + $expiresIn);
        $now = date('Y-m-d H:i:s');

        $existing = $DB->request([
            'FROM'  => self::TABLE,
            'WHERE' => [
                'users_id' => $usersId,
                'tenant_id' => $tenantId,
            ],
            'LIMIT' => 1,
        ])->current();

        $fields = [
            'users_id'        => $usersId,
            'tenant_id'       => $tenantId,
            'entra_object_id' => $teamsUserId,
            'entra_email'     => $email !== '' ? $email : null,
            'access_token'    => $encryptedAccessToken,
            'refresh_token'   => $encryptedRefreshToken,
            'expires_at'      => $expiresAt,
            'is_active'       => 1,
            'date_mod'        => $now,
        ];

        if (is_array($existing) && !empty($existing['id'])) {
            $DB->update(self::TABLE, $fields, ['id' => (int) $existing['id']]);
        } else {
            $fields['date_creation'] = $now;
            $DB->insert(self::TABLE, $fields);
        }
    }

    public function getActive(int $usersId, string $tenantId): ?array
    {
        global $DB;

        $record = $DB->request([
            'FROM'  => self::TABLE,
            'WHERE' => [
                'users_id' => $usersId,
                'tenant_id' => $tenantId,
                'is_active' => 1,
            ],
            'LIMIT' => 1,
        ])->current();

        if (!is_array($record) || empty($record['id'])) {
            return null;
        }

        $key = new GLPIKey();
        $record['access_token'] = $key->decrypt((string) $record['access_token']);
        $record['refresh_token'] = $key->decrypt((string) ($record['refresh_token'] ?? ''));

        return $record;
    }

    public function findByTeamsIdentity(array $identity): ?array
    {
        global $DB;

        $tenantId = trim((string) ($identity['tenant_id'] ?? $identity['teams_tenant_id'] ?? ''));
        $objectId = trim((string) ($identity['aad_object_id'] ?? $identity['user_id'] ?? $identity['teams_user_id'] ?? ''));
        $email = trim((string) ($identity['email'] ?? $identity['teams_email'] ?? ''));
        if ($tenantId === '') {
            return null;
        }

        $record = null;
        if ($objectId !== '') {
            $record = $DB->request([
                'FROM' => self::TABLE,
                'WHERE' => [
                    'tenant_id' => $tenantId,
                    'entra_object_id' => $objectId,
                    'is_active' => 1,
                ],
                'LIMIT' => 1,
            ])->current();
        }
        if ((!is_array($record) || empty($record['id'])) && $email !== '') {
            $record = $DB->request([
                'FROM' => self::TABLE,
                'WHERE' => [
                    'tenant_id' => $tenantId,
                    'entra_email' => $email,
                    'is_active' => 1,
                ],
                'LIMIT' => 1,
            ])->current();
        }
        if (!is_array($record) || empty($record['id'])) {
            return null;
        }

        $key = new GLPIKey();
        $record['access_token'] = $key->decrypt((string) $record['access_token']);
        $record['refresh_token'] = $key->decrypt((string) ($record['refresh_token'] ?? ''));

        return $record;
    }

    public function updateTokens(int $linkId, array $tokenPayload): void
    {
        global $DB;

        if ($linkId <= 0 || empty($tokenPayload['access_token']) || empty($tokenPayload['expires_in'])) {
            throw new TeamsIntegrationException('The refreshed GLPI token payload is incomplete.');
        }

        $key = new GLPIKey();
        $fields = [
            'access_token' => $this->encrypt($key, (string) $tokenPayload['access_token']),
            'expires_at' => date('Y-m-d H:i:s', time() + max(60, (int) $tokenPayload['expires_in'])),
            'date_mod' => date('Y-m-d H:i:s'),
            'is_active' => 1,
        ];
        if (!empty($tokenPayload['refresh_token'])) {
            $fields['refresh_token'] = $this->encrypt($key, (string) $tokenPayload['refresh_token']);
        }

        $DB->update(self::TABLE, $fields, ['id' => $linkId]);
    }

    private function encrypt(GLPIKey $key, string $value): string
    {
        $encrypted = $key->encrypt($value);
        if ($encrypted === '') {
            throw new TeamsIntegrationException('The GLPI security key is unavailable; token was not stored.');
        }

        return $encrypted;
    }
}
