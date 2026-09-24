<?php

namespace GlpiPlugin\Glpimsteams\Service;

final class NotificationService
{
    public function __construct(
        private readonly ConfigurationService $configuration,
        private readonly OutboxService $outbox,
        private readonly ContentSanitizer $sanitizer
    ) {
    }

    public function ticketCreated(object $ticket): void
    {
        if (!$this->enabled('notify_new_ticket')) {
            return;
        }

        $fields = is_array($ticket->fields ?? null) ? $ticket->fields : [];
        $ticketId = (int) ($fields['id'] ?? 0);
        if ($ticketId <= 0) {
            return;
        }

        $requester = $this->requester($fields, $ticketId);

        $this->queue('ticket.created', (string) $ticketId, [
            'event_type' => 'Novo chamado',
            'ticket_id' => $ticketId,
            'entity_id' => (int) ($fields['entities_id'] ?? 0),
            'title' => $this->sanitizer->text($fields['name'] ?? '', 300),
            'status' => $this->sanitizer->text($fields['status'] ?? '', 100),
            'priority' => $this->sanitizer->text($fields['priority'] ?? '', 100),
            'content' => $this->sanitizer->text($fields['content'] ?? '', 1200),
            'requester_name' => $requester['name'],
            'requester_email' => $requester['email'],
        ]);
    }

    public function ticketUpdated(object $ticket): void
    {
        $fields = is_array($ticket->fields ?? null) ? $ticket->fields : [];
        $ticketId = (int) ($fields['id'] ?? 0);
        if ($ticketId <= 0) {
            return;
        }

        $updates = isset($ticket->updates) && is_array($ticket->updates) ? $ticket->updates : [];
        $changed = array_map('strval', array_keys($updates));
        $eventType = 'Chamado atualizado';
        $flag = 'notify_ticket_update';

        if (in_array('status', $changed, true)) {
            $statusId = is_array($fields['status'] ?? null)
                ? (int) ($fields['status']['id'] ?? 0)
                : (int) ($fields['status'] ?? 0);
            $solvedId = defined('CommonITILObject::SOLVED') ? (int) constant('CommonITILObject::SOLVED') : null;
            $closedId = defined('CommonITILObject::CLOSED') ? (int) constant('CommonITILObject::CLOSED') : null;
            if ($solvedId !== null && $statusId === $solvedId && $this->enabled('notify_solution')) {
                $eventType = 'Chamado solucionado';
                $flag = 'notify_solution';
            } elseif ($closedId !== null && $statusId === $closedId && $this->enabled('notify_closure')) {
                $eventType = 'Chamado fechado';
                $flag = 'notify_closure';
            } elseif ($this->enabled('notify_status')) {
                $eventType = 'Status alterado';
                $flag = 'notify_status';
            }
        } elseif (in_array('priority', $changed, true) && $this->enabled('notify_priority')) {
            $eventType = 'Prioridade alterada';
            $flag = 'notify_priority';
        } elseif (array_intersect(['users_id_assign', '_users_id_assign'], $changed) !== [] && $this->enabled('notify_assignment')) {
            $eventType = 'Atribuição alterada';
            $flag = 'notify_assignment';
        }

        if (!$this->enabled($flag)) {
            return;
        }

        $requester = $this->requester($fields, $ticketId);

        $eventId = (string) ($fields['date_mod'] ?? '') . ':' . $ticketId;
        if ($eventId === ':' . $ticketId) {
            $eventId = (string) $ticketId . ':' . implode(',', $changed);
        }

        $this->queue('ticket.updated', $eventId, [
            'event_type' => $eventType,
            'ticket_id' => $ticketId,
            'entity_id' => (int) ($fields['entities_id'] ?? 0),
            'title' => $this->sanitizer->text($fields['name'] ?? '', 300),
            'status' => $this->sanitizer->text($fields['status'] ?? '', 100),
            'priority' => $this->sanitizer->text($fields['priority'] ?? '', 100),
            'content' => 'Campos alterados: ' . $this->sanitizer->text(implode(', ', $changed), 500),
            'requester_name' => $requester['name'],
            'requester_email' => $requester['email'],
        ]);
    }

    public function followupCreated(object $followup): void
    {
        if (!$this->enabled('notify_followup')) {
            return;
        }

        $fields = is_array($followup->fields ?? null) ? $followup->fields : [];
        $followupId = (int) ($fields['id'] ?? 0);
        $ticketId = (int) ($fields['items_id'] ?? 0);
        if ($followupId <= 0 || $ticketId <= 0) {
            return;
        }

        $requester = $this->requester([], $ticketId);

        $this->queue('followup.created', (string) $followupId, [
            'event_type' => 'Novo acompanhamento',
            'ticket_id' => $ticketId,
            'title' => 'Chamado #' . $ticketId,
            'content' => $this->sanitizer->text($fields['content'] ?? '', 1600),
            'requester_name' => $requester['name'],
            'requester_email' => $requester['email'],
        ]);
    }

    public function solutionCreated(object $solution): void
    {
        if (!$this->enabled('notify_solution')) {
            return;
        }

        $fields = is_array($solution->fields ?? null) ? $solution->fields : [];
        $solutionId = (int) ($fields['id'] ?? 0);
        $ticketId = (int) ($fields['items_id'] ?? $fields['tickets_id'] ?? 0);
        if ($solutionId <= 0 || $ticketId <= 0) {
            return;
        }

        $requester = $this->requester([], $ticketId);

        $content = $fields['content'] ?? $fields['comment'] ?? '';
        $this->queue('solution.created', (string) $solutionId, [
            'event_type' => 'Solução adicionada',
            'ticket_id' => $ticketId,
            'title' => 'Chamado #' . $ticketId,
            'content' => $this->sanitizer->text($content, 1600),
            'requester_name' => $requester['name'],
            'requester_email' => $requester['email'],
        ]);
    }

    private function enabled(string $field): bool
    {
        $config = $this->configuration->get();
        return !empty($config['enabled']) && !empty($config[$field]);
    }

    private function queue(string $eventType, string $eventId, array $payload): void
    {
        $this->outbox->enqueue($eventType, $eventId, $payload);
    }

    private function requester(array $fields, int $ticketId): array
    {
        $usersId = $this->scalarInt($fields['users_id_recipient'] ?? null);
        if ($usersId <= 0 && $ticketId > 0 && class_exists('Ticket')) {
            $ticket = new \Ticket();
            if ($ticket->getFromDB($ticketId)) {
                $usersId = $this->scalarInt($ticket->fields['users_id_recipient'] ?? null);
            }
        }

        if ($usersId <= 0 || !class_exists('User')) {
            return ['name' => '', 'email' => ''];
        }

        $user = new \User();
        if (!$user->getFromDB($usersId)) {
            return ['name' => '', 'email' => ''];
        }

        $email = '';
        foreach (['email', 'email1', 'email2', 'email3', 'email4'] as $field) {
            if (!empty($user->fields[$field])) {
                $email = (string) $user->fields[$field];
                break;
            }
        }

        $name = trim((string) ($user->fields['name'] ?? ''));
        if ($name === '') {
            $name = trim(implode(' ', array_filter([
                (string) ($user->fields['firstname'] ?? ''),
                (string) ($user->fields['realname'] ?? ''),
            ])));
        }

        return [
            'name' => $this->sanitizer->text($name, 160),
            'email' => $this->sanitizer->text($email, 320),
        ];
    }

    private function scalarInt(mixed $value): int
    {
        return is_scalar($value) ? (int) $value : 0;
    }
}
