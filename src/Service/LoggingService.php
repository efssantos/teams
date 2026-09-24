<?php

namespace GlpiPlugin\Teams\Service;

use Toolbox;

final class LoggingService
{
    private const REDACTED = '[REDACTED]';

    public function info(string $message, array $context = []): string
    {
        return $this->write('INFO', $message, $context);
    }

    public function warning(string $message, array $context = []): string
    {
        return $this->write('WARNING', $message, $context);
    }

    public function error(string $message, array $context = []): string
    {
        return $this->write('ERROR', $message, $context);
    }

    public function write(string $level, string $message, array $context = []): string
    {
        $requestId = isset($context['request_id']) && is_string($context['request_id'])
            ? $context['request_id']
            : bin2hex(random_bytes(16));

        $context['request_id'] = $requestId;
        $context = $this->redact($context);

        $line = sprintf('[%s] [%s] [%s] %s', date('c'), $level, $requestId, $message);

        if ($context !== []) {
            $encoded = json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if ($encoded !== false) {
                $line .= ' ' . $encoded;
            }
        }

        // GLPI owns the destination and rotation of plugin log files.
        Toolbox::logInFile('teams', $line . PHP_EOL);

        return $requestId;
    }

    private function redact(array $value): array
    {
        $result = [];

        foreach ($value as $key => $item) {
            $normalizedKey = strtolower((string) $key);
            $isSensitive = str_contains($normalizedKey, 'secret')
                || str_contains($normalizedKey, 'token')
                || str_contains($normalizedKey, 'password');

            if ($isSensitive) {
                $result[$key] = self::REDACTED;
            } elseif (is_array($item)) {
                $result[$key] = $this->redact($item);
            } else {
                $result[$key] = $item;
            }
        }

        return $result;
    }
}
