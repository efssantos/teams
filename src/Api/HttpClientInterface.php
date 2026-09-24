<?php

namespace GlpiPlugin\Teams\Api;

interface HttpClientInterface
{
    public function request(string $method, string $url, array $headers = [], array|string|null $body = null): HttpResponse;
}
