<?php

namespace GlpiPlugin\Teams\Api;

use GlpiPlugin\Teams\Exception\TeamsIntegrationException;

final class HttpResponse
{
    public function __construct(
        public readonly int $statusCode,
        public readonly string $body,
        public readonly array $headers = []
    ) {
    }

    public function json(): array
    {
        try {
            $decoded = json_decode($this->body, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new TeamsIntegrationException('The OAuth provider returned invalid JSON.');
        }

        if (!is_array($decoded)) {
            throw new TeamsIntegrationException('The OAuth provider returned an invalid response.');
        }

        return $decoded;
    }
}
