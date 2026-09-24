<?php

namespace GlpiPlugin\Glpimsteams\Authentication;

use GlpiPlugin\Glpimsteams\Api\GlpiOAuthClient;
use GlpiPlugin\Glpimsteams\Exception\TeamsIntegrationException;
use GlpiPlugin\Glpimsteams\Service\ConfigurationService;
use GlpiPlugin\Glpimsteams\Service\LoggingService;

final class OAuthService
{
    public function __construct(
        private readonly ConfigurationService $configuration,
        private readonly GlpiOAuthClient $glpiClient,
        private readonly OAuthStateStore $stateStore,
        private readonly TokenStorageService $tokenStorage,
        private readonly LoggingService $logger
    ) {
    }

    public function beginGlpiLink(array $teamsIdentity): string
    {
        $configuration = $this->configuration->get();
        $this->requireConfiguration($configuration);
        $state = $this->stateStore->create($teamsIdentity);

        $this->logger->info('GLPI OAuth authorization started', [
            'teams_tenant_id' => $teamsIdentity['tenant_id'] ?? null,
        ]);

        return $this->glpiClient->buildAuthorizationUrl($configuration, $state);
    }

    public function completeGlpiLink(string $state, string $code, int $glpiUserId): array
    {
        if ($code === '' || strlen($code) > 4096) {
            throw new TeamsIntegrationException('Invalid OAuth authorization code.');
        }

        if ($glpiUserId <= 0) {
            throw new TeamsIntegrationException('The GLPI session is not authenticated.');
        }

        $identity = $this->stateStore->consume($state);
        $configuration = $this->configuration->get();
        $this->requireConfiguration($configuration);
        $tokenPayload = $this->glpiClient->exchangeAuthorizationCode($configuration, $code);
        $this->tokenStorage->save($glpiUserId, $identity, $tokenPayload);

        $this->logger->info('GLPI OAuth authorization completed', [
            'glpi_user_id' => $glpiUserId,
            'teams_tenant_id' => $identity['teams_tenant_id'],
        ]);

        return [
            'glpi_user_id' => $glpiUserId,
            'teams_user_id' => $identity['teams_user_id'],
            'teams_email' => $identity['teams_email'],
            'teams_tenant_id' => $identity['teams_tenant_id'],
        ];
    }

    private function requireConfiguration(array $configuration): void
    {
        foreach (['base_url', 'glpi_api_client_id', 'glpi_api_client_secret', 'glpi_redirect_uri'] as $field) {
            if (trim((string) ($configuration[$field] ?? '')) === '') {
                throw new TeamsIntegrationException('GLPI OAuth configuration is incomplete.');
            }
        }
    }
}
