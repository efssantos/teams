<?php

namespace GlpiPlugin\Teams\Controller;

use GlpiPlugin\Teams\Security\BotFrameworkTokenValidator;
use GlpiPlugin\Teams\Service\EventStoreService;
use GlpiPlugin\Teams\Service\LoggingService;
use GlpiPlugin\Teams\Service\TeamsCommandService;
use GlpiPlugin\Teams\Exception\TeamsIntegrationException;

final class TeamsWebhookController
{
    public function __construct(
        private readonly BotFrameworkTokenValidator $validator,
        private readonly EventStoreService $events,
        private readonly TeamsCommandService $commands,
        private readonly LoggingService $logger
    ) {
    }

    public function handle(string $authorizationHeader, string $body): array
    {
        if (strlen($body) > 1_048_576) {
            return [413, ['error' => 'payload_too_large']];
        }

        $claimed = false;
        $externalId = '';
        try {
            $activity = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($activity) || empty($activity['id']) || empty($activity['type'])) {
                return [400, ['error' => 'invalid_activity']];
            }
            $this->validator->validate($authorizationHeader, $activity);

            $externalId = (string) $activity['id'];
            if (!$this->events->claim('teams-bot', $externalId, (string) $activity['type'], $activity)) {
                return [200, ['status' => 'already_processed']];
            }
            $claimed = true;

            $this->commands->handle($activity);
            $this->events->markProcessed('teams-bot', $externalId);
            return [200, ['status' => 'processed']];
        } catch (\JsonException) {
            return [400, ['error' => 'invalid_json']];
        } catch (TeamsIntegrationException $exception) {
            if ($claimed) {
                $this->events->markProcessed('teams-bot', $externalId);
                $this->logger->warning('Teams command rejected', ['error_type' => get_class($exception)]);
                return [200, ['status' => 'accepted']];
            }
            $this->logger->warning('Teams webhook rejected', ['error_type' => get_class($exception)]);
            return [401, ['error' => 'unauthorized']];
        } catch (\Throwable $exception) {
            if ($claimed) {
                $this->events->markProcessed('teams-bot', $externalId);
            }
            $this->logger->error('Teams webhook processing failed', ['error_type' => get_class($exception)]);
            return [200, ['status' => 'accepted']];
        }
    }
}
