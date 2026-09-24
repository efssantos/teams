<?php

namespace GlpiPlugin\Teams\Exception;

final class GlpiApiException extends TeamsIntegrationException
{
    public function __construct(
        string $message,
        public readonly int $statusCode = 0,
        public readonly ?int $retryAfter = null
    ) {
        parent::__construct($message);
    }
}
