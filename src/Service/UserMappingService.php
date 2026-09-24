<?php

namespace GlpiPlugin\Glpimsteams\Service;

use GlpiPlugin\Glpimsteams\Api\GlpiClient;
use GlpiPlugin\Glpimsteams\Authentication\TokenStorageService;
use GlpiPlugin\Glpimsteams\Exception\TeamsIntegrationException;

final class UserMappingService
{
    public function __construct(
        private readonly TokenStorageService $tokens,
        private readonly GlpiClient $glpi,
        private readonly ConfigurationService $configuration
    ) {
    }

    public function accessForTeamsIdentity(array $identity): array
    {
        $record = $this->tokens->findByTeamsIdentity($identity);
        if ($record === null) {
            throw new TeamsIntegrationException('Teams user is not linked to a GLPI user.');
        }

        $expiresAt = strtotime((string) ($record['expires_at'] ?? '')) ?: 0;
        if ($expiresAt > time() + 60) {
            return $record;
        }

        $refreshToken = (string) ($record['refresh_token'] ?? '');
        if ($refreshToken === '') {
            throw new TeamsIntegrationException('The GLPI OAuth link has expired and cannot be refreshed.');
        }

        $tokenPayload = $this->glpi->refreshToken($this->configuration->get(), $refreshToken);
        $this->tokens->updateTokens((int) $record['id'], $tokenPayload);
        return $this->tokens->findByTeamsIdentity($identity) ?? throw new TeamsIntegrationException('The refreshed GLPI OAuth link could not be loaded.');
    }
}
