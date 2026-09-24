<?php

namespace GlpiPlugin\Teams\Service;

use GlpiPlugin\Teams\Api\TeamsBotClient;
use GlpiPlugin\Teams\Exception\TeamsIntegrationException;

final class TeamsMessageService
{
    public function __construct(
        private readonly ConfigurationService $configuration,
        private readonly TeamsRouteService $routes,
        private readonly TeamsBotClient $botClient,
        private readonly ContentSanitizer $sanitizer
    ) {
    }

    public function sendTicketEvent(array $payload, ?int $routeId = null): string
    {
        $config = $this->configuration->get();
        if (empty($config['enabled'])) {
            throw new TeamsIntegrationException('Teams integration is disabled.');
        }

        $route = $this->routes->getRoute($routeId);
        $ticketId = (int) ($payload['ticket_id'] ?? 0);
        $title = $this->sanitizer->text($payload['title'] ?? 'GLPI ticket', 300);
        $eventType = $this->sanitizer->text($payload['event_type'] ?? 'updated', 80);
        $status = $this->sanitizer->text($payload['status'] ?? '', 100);
        $priority = $this->sanitizer->text($payload['priority'] ?? '', 100);
        $content = $this->sanitizer->text($payload['content'] ?? '', 1200);
        $requesterName = $this->sanitizer->text($payload['requester_name'] ?? '', 160);
        $requesterEmail = $this->sanitizer->text($payload['requester_email'] ?? '', 320);
        $link = $this->ticketLink($config, $ticketId);

        $body = sprintf("GLPI: %s\nChamado #%d — %s", $eventType, $ticketId, $title);
        if ($status !== '') {
            $body .= "\nStatus: " . $status;
        }
        if ($priority !== '') {
            $body .= "\nPrioridade: " . $priority;
        }
        if ($requesterName !== '' || $requesterEmail !== '') {
            $requester = trim($requesterName . ($requesterName !== '' && $requesterEmail !== '' ? ' — ' : '') . $requesterEmail);
            $body .= "\nRequerente: " . $requester;
        }
        if ($content !== '') {
            $body .= "\n\n" . $content;
        }

        $facts = array_values(array_filter([
            $status !== '' ? ['title' => 'Status', 'value' => $status] : null,
            $priority !== '' ? ['title' => 'Prioridade', 'value' => $priority] : null,
        ]));
        $cardBody = [
            ['type' => 'TextBlock', 'size' => 'Medium', 'weight' => 'Bolder', 'text' => 'GLPI — ' . $eventType],
            ['type' => 'TextBlock', 'text' => '#' . $ticketId . ' — ' . $title, 'wrap' => true],
        ];
        if ($facts !== []) {
            $cardBody[] = ['type' => 'FactSet', 'facts' => $facts];
        }
        if ($requesterName !== '' || $requesterEmail !== '') {
            $requester = trim($requesterName . ($requesterName !== '' && $requesterEmail !== '' ? ' — ' : '') . $requesterEmail);
            $cardBody[] = ['type' => 'FactSet', 'facts' => [[
                'title' => 'Requerente',
                'value' => $requester,
            ]]];
        }
        if ($content !== '') {
            $cardBody[] = ['type' => 'TextBlock', 'text' => $content, 'wrap' => true];
        }

        $activity = [
            'type' => 'message',
            'text' => $body,
            'attachments' => [[
                'contentType' => 'application/vnd.microsoft.card.adaptive',
                'content' => [
                    '$schema' => 'http://adaptivecards.io/schemas/adaptive-card.json',
                    'type' => 'AdaptiveCard',
                    'version' => '1.4',
                    'body' => $cardBody,
                    'actions' => $link !== '' ? [[
                        'type' => 'Action.OpenUrl',
                        'title' => 'Abrir chamado no GLPI',
                        'url' => $link,
                    ]] : [],
                ],
            ]],
        ];

        return $this->botClient->sendActivity(
            (string) $route['service_url'],
            (string) $route['conversation_id'],
            $activity
        );
    }

    private function ticketLink(array $configuration, int $ticketId): string
    {
        $baseUrl = rtrim(trim((string) ($configuration['base_url'] ?? '')), '/');
        if ($baseUrl === '' || $ticketId <= 0) {
            return '';
        }

        return $baseUrl . '/front/ticket.form.php?id=' . $ticketId;
    }
}
