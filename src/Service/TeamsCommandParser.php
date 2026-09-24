<?php

namespace GlpiPlugin\Teams\Service;

final class TeamsCommandParser
{
    public function __construct(private readonly ContentSanitizer $sanitizer)
    {
    }

    public function parse(string $text): array
    {
        $text = trim($text);
        $lower = function_exists('mb_strtolower') ? mb_strtolower($text) : strtolower($text);
        if ($lower === 'ajuda' || $lower === 'help' || $lower === '') {
            return ['name' => 'help'];
        }
        if ($lower === 'vincular' || $lower === 'associar' || $lower === 'link') {
            return ['name' => 'link'];
        }
        if ($lower === 'meus chamados' || $lower === 'meus tickets') {
            return ['name' => 'list'];
        }

        if (preg_match('/^(?:chamado|ticket)\s+#?(\d+)$/iu', $text, $match)) {
            return ['name' => 'show', 'ticket_id' => (int) $match[1]];
        }
        if (preg_match('/^(?:acompanhar|followup)\s+#?(\d+)\s*\|\s*(.+)$/ius', $text, $match)) {
            return [
                'name' => 'followup',
                'ticket_id' => (int) $match[1],
                'content' => $this->sanitizer->text($match[2], 1600),
            ];
        }
        if (preg_match('/^status\s+#?(\d+)\s*\|\s*(\d+)$/iu', $text, $match)) {
            return ['name' => 'status', 'ticket_id' => (int) $match[1], 'status_id' => (int) $match[2]];
        }
        if (preg_match('/^(?:novo chamado|new ticket)\s*\|\s*(.+?)\s*\|\s*(.+)$/ius', $text, $match)) {
            return [
                'name' => 'create',
                'title' => $this->sanitizer->text($match[1], 300),
                'content' => $this->sanitizer->text($match[2], 3000),
            ];
        }

        return ['name' => 'unknown'];
    }
}
