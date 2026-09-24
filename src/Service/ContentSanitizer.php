<?php

namespace GlpiPlugin\Glpimsteams\Service;

final class ContentSanitizer
{
    public function text(mixed $value, int $maxLength = 2000): string
    {
        $text = trim(strip_tags((string) $value));
        $text = preg_replace(
            '/\b(password|passwd|senha|token|access[_ -]?token|refresh[_ -]?token|client[_ -]?secret|secret)\b\s*[:=]\s*[^\s,;]+/iu',
            '$1: [REDACTED]',
            $text
        ) ?? '';
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;

        return mb_substr($text, 0, max(1, $maxLength));
    }
}
