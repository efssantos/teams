<?php

namespace GlpiPlugin\Glpimsteams\Exception;

final class ProviderHttpException extends TeamsIntegrationException
{
    public function __construct(
        string $message,
        public readonly int $statusCode,
        public readonly ?int $retryAfter = null
    ) {
        parent::__construct($message);
    }
}
