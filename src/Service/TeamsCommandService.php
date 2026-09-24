<?php

namespace GlpiPlugin\Glpimsteams\Service;

use GlpiPlugin\Glpimsteams\Api\GlpiClient;
use GlpiPlugin\Glpimsteams\Api\TeamsBotClient;
use GlpiPlugin\Glpimsteams\Authentication\OAuthService;
use GlpiPlugin\Glpimsteams\Exception\GlpiApiException;
use GlpiPlugin\Glpimsteams\Exception\TeamsIntegrationException;

final class TeamsCommandService
{
    public function __construct(
        private readonly GlpiClient $glpi,
        private readonly UserMappingService $mapping,
        private readonly TeamsBotClient $bot,
        private readonly ContentSanitizer $sanitizer,
        private readonly OAuthService $oauth,
        private readonly TeamsCommandParser $parser
    ) {
    }

    public function handle(array $activity): void
    {
        if (($activity['type'] ?? '') !== 'message') {
            return;
        }

        $rawText = preg_replace('/<at>.*?<\/at>/isu', ' ', (string) ($activity['text'] ?? '')) ?? '';
        $text = $this->sanitizer->text($rawText, 4000);
        $command = $this->parser->parse($text);
        $serviceUrl = (string) ($activity['serviceUrl'] ?? '');
        $conversation = is_array($activity['conversation'] ?? null) ? $activity['conversation'] : [];
        $conversationId = (string) ($conversation['id'] ?? '');
        if ($serviceUrl === '' || $conversationId === '') {
            throw new TeamsIntegrationException('Teams activity conversation is incomplete.');
        }

        $identity = $this->identity($activity);
        $reply = $this->execute($command, $identity);
        $this->bot->sendActivity($serviceUrl, $conversationId, [
            'type' => 'message',
            'text' => $reply,
        ]);
    }

    private function execute(array $command, array $identity): string
    {
        if ($command['name'] === 'help') {
            return "Comandos GLPI:\n"
                . "novo chamado | título | descrição\n"
                . "vincular\n"
                . "meus chamados\n"
                . "chamado <número>\n"
                . "acompanhar <número> | comentário\n"
                . "status <número> | <id do status>";
        }

        if ($command['name'] === 'link') {
            try {
                $identity['user_id'] = $identity['aad_object_id'] !== ''
                    ? $identity['aad_object_id']
                    : $identity['user_id'];
                $url = $this->oauth->beginGlpiLink($identity);
                return "Abra este endereço para vincular sua conta Teams ao GLPI:\n" . $url;
            } catch (TeamsIntegrationException) {
                return 'Não foi possível iniciar o vínculo com o GLPI. Verifique a configuração do administrador.';
            }
        }

        try {
            $access = $this->mapping->accessForTeamsIdentity($identity);
        } catch (TeamsIntegrationException) {
            return 'Usuário não associado ao GLPI. Entre em contato com o administrador.';
        }

        $token = (string) $access['access_token'];
        $usersId = (int) $access['users_id'];
        try {
            return match ($command['name']) {
                'create' => $this->createTicket($token, $command),
                'list' => $this->listTickets($token, $usersId),
                'show' => $this->showTicket($token, $command['ticket_id']),
                'followup' => $this->addFollowup($token, $command['ticket_id'], $command['content']),
                'status' => $this->updateStatus($token, $command['ticket_id'], $command['status_id']),
                default => 'Comando não reconhecido. Envie "ajuda" para ver as opções.',
            };
        } catch (GlpiApiException $exception) {
            return match ($exception->statusCode) {
                401, 403 => 'O GLPI recusou a operação por autenticação ou permissão.',
                404 => 'Chamado não encontrado ou sem acesso.',
                429 => 'O GLPI limitou temporariamente as requisições. Tente novamente em instantes.',
                default => 'Não foi possível concluir a operação no GLPI.',
            };
        }
    }

    private function createTicket(string $token, array $command): string
    {
        $ticket = $this->glpi->createTicket($token, $command['title'], $command['content']);
        $id = (int) ($ticket['id'] ?? 0);
        return $id > 0
            ? 'Chamado criado com sucesso: #' . $id
            : 'O GLPI aceitou a solicitação, mas não retornou o número do chamado.';
    }

    private function listTickets(string $token, int $usersId): string
    {
        $tickets = $this->glpi->listTickets($token);
        $owned = [];
        foreach ($tickets as $ticket) {
            if (!is_array($ticket) || !$this->isRequester($ticket, $usersId)) {
                continue;
            }
            $owned[] = sprintf(
                '#%d — %s (%s)',
                (int) ($ticket['id'] ?? 0),
                $this->sanitizer->text($ticket['name'] ?? 'Sem título', 160),
                $this->statusName($ticket['status'] ?? '')
            );
        }

        return $owned === [] ? 'Nenhum chamado seu foi encontrado.' : "Seus chamados:\n" . implode("\n", array_slice($owned, 0, 20));
    }

    private function showTicket(string $token, int $ticketId): string
    {
        $ticket = $this->glpi->getTicket($token, $ticketId);
        return sprintf(
            "Chamado #%d\n%s\nStatus: %s\nPrioridade: %s\n\n%s",
            (int) ($ticket['id'] ?? $ticketId),
            $this->sanitizer->text($ticket['name'] ?? 'Sem título', 300),
            $this->statusName($ticket['status'] ?? ''),
            $this->statusName($ticket['priority'] ?? ''),
            $this->sanitizer->text($ticket['content'] ?? '', 1200)
        );
    }

    private function addFollowup(string $token, int $ticketId, string $content): string
    {
        $this->glpi->addFollowup($token, $ticketId, $content);
        return 'Acompanhamento adicionado com sucesso ao chamado #' . $ticketId . '.';
    }

    private function updateStatus(string $token, int $ticketId, int $statusId): string
    {
        $this->glpi->updateStatus($token, $ticketId, $statusId);
        return 'Status do chamado #' . $ticketId . ' atualizado.';
    }

    private function identity(array $activity): array
    {
        $from = is_array($activity['from'] ?? null) ? $activity['from'] : [];
        $channelData = is_array($activity['channelData'] ?? null) ? $activity['channelData'] : [];
        $tenantData = is_array($channelData['tenant'] ?? null) ? $channelData['tenant'] : [];
        $conversation = is_array($activity['conversation'] ?? null) ? $activity['conversation'] : [];
        $tenant = (string) ($tenantData['id'] ?? $conversation['tenantId'] ?? '');
        return [
            'tenant_id' => $tenant,
            'aad_object_id' => (string) ($from['aadObjectId'] ?? ''),
            'user_id' => (string) ($from['id'] ?? ''),
            'email' => (string) ($from['email'] ?? ''),
        ];
    }

    private function isRequester(array $ticket, int $usersId): bool
    {
        foreach (($ticket['team'] ?? []) as $member) {
            if (is_array($member) && ($member['role'] ?? '') === 'requester' && (int) ($member['id'] ?? 0) === $usersId) {
                return true;
            }
        }

        return false;
    }

    private function statusName(mixed $value): string
    {
        if (is_array($value)) {
            return $this->sanitizer->text($value['name'] ?? $value['id'] ?? '', 100);
        }

        return $this->sanitizer->text($value, 100);
    }
}
