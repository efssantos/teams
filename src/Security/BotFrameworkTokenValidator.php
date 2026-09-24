<?php

namespace GlpiPlugin\Teams\Security;

use GlpiPlugin\Teams\Api\HttpClientInterface;
use GlpiPlugin\Teams\Exception\TeamsIntegrationException;
use GlpiPlugin\Teams\Service\ConfigurationService;

final class BotFrameworkTokenValidator
{
    private const METADATA_URL = 'https://login.botframework.com/v1/.well-known/openidconfiguration';
    private const TABLE = 'glpi_plugin_teams_bot_keys';
    private const CLOCK_SKEW = 300;

    public function __construct(
        private readonly ConfigurationService $configuration,
        private readonly HttpClientInterface $httpClient
    ) {
    }

    public function validate(string $authorizationHeader, array $activity): array
    {
        if (!preg_match('/^Bearer\s+([^\s]+)$/i', trim($authorizationHeader), $matches)) {
            throw new TeamsIntegrationException('Bot authorization header is invalid.');
        }

        $token = $matches[1];
        [$encodedHeader, $encodedPayload, $encodedSignature] = $this->parts($token);
        $header = $this->decodeJson($encodedHeader);
        $claims = $this->decodeJson($encodedPayload);
        $signature = $this->base64UrlDecode($encodedSignature);

        $algorithm = (string) ($header['alg'] ?? '');
        $keyId = (string) ($header['kid'] ?? $header['x5t'] ?? '');
        if ($algorithm === '' || $keyId === '' || $signature === '') {
            throw new TeamsIntegrationException('Bot JWT header is incomplete.');
        }

        $keySet = $this->loadKeySet(false);
        $key = $this->findKey($keySet['keys'] ?? [], $keyId);
        if ($key === null || empty($key['x5c'][0])) {
            $keySet = $this->loadKeySet(true);
            $key = $this->findKey($keySet['keys'] ?? [], $keyId);
        }
        if ($key === null || empty($key['x5c'][0])) {
            throw new TeamsIntegrationException('Bot JWT signing key was not found.');
        }

        $supportedAlgorithms = $keySet['algorithms'] ?? ['RS256'];
        if (!in_array($algorithm, $supportedAlgorithms, true) || !in_array($algorithm, ['RS256', 'RS384', 'RS512'], true)) {
            throw new TeamsIntegrationException('Bot JWT algorithm is not allowed.');
        }

        $certificate = "-----BEGIN CERTIFICATE-----\n"
            . chunk_split((string) $key['x5c'][0], 64, "\n")
            . "-----END CERTIFICATE-----\n";
        $publicKey = openssl_pkey_get_public($certificate);
        if ($publicKey === false) {
            throw new TeamsIntegrationException('Bot JWT public key is invalid.');
        }

        $verified = openssl_verify(
            $encodedHeader . '.' . $encodedPayload,
            $signature,
            $publicKey,
            match ($algorithm) {
                'RS384' => OPENSSL_ALGO_SHA384,
                'RS512' => OPENSSL_ALGO_SHA512,
                default => OPENSSL_ALGO_SHA256,
            }
        );
        if ($verified !== 1) {
            throw new TeamsIntegrationException('Bot JWT signature is invalid.');
        }

        $this->validateClaims($claims, $activity);
        return $claims;
    }

    private function validateClaims(array $claims, array $activity): void
    {
        $config = $this->configuration->get();
        $issuer = (string) ($claims['iss'] ?? '');
        $audience = $claims['aud'] ?? null;
        $expectedAudience = trim((string) ($config['bot_app_id'] ?? ''));
        $serviceUrl = (string) ($claims['serviceurl'] ?? $claims['serviceUrl'] ?? '');
        $activityServiceUrl = (string) ($activity['serviceUrl'] ?? '');
        $now = time();

        if (empty($config['enabled'])) {
            throw new TeamsIntegrationException('Teams integration is disabled.');
        }
        if (($activity['channelId'] ?? '') !== 'msteams') {
            throw new TeamsIntegrationException('Only Microsoft Teams activities are accepted.');
        }
        if ($issuer !== 'https://api.botframework.com') {
            throw new TeamsIntegrationException('Bot JWT issuer is invalid.');
        }
        if ($expectedAudience === '' || !($audience === $expectedAudience || (is_array($audience) && in_array($expectedAudience, $audience, true)))) {
            throw new TeamsIntegrationException('Bot JWT audience is invalid.');
        }
        if ($serviceUrl === '' || $activityServiceUrl === '' || rtrim($serviceUrl, '/') !== rtrim($activityServiceUrl, '/')) {
            throw new TeamsIntegrationException('Bot JWT service URL does not match the activity.');
        }
        if (!isset($claims['exp']) || !is_numeric($claims['exp']) || (int) $claims['exp'] < $now - self::CLOCK_SKEW) {
            throw new TeamsIntegrationException('Bot JWT is expired.');
        }
        if (isset($claims['nbf']) && (!is_numeric($claims['nbf']) || (int) $claims['nbf'] > $now + self::CLOCK_SKEW)) {
            throw new TeamsIntegrationException('Bot JWT is not active yet.');
        }
    }

    private function loadKeySet(bool $forceRefresh): array
    {
        global $DB;

        $record = $DB->request([
            'FROM' => self::TABLE,
            'WHERE' => ['id' => 1],
            'LIMIT' => 1,
        ])->current();
        if (!$forceRefresh && is_array($record) && !empty($record['jwks']) && strtotime((string) ($record['fetched_at'] ?? '')) >= time() - 86400) {
            $keySet = json_decode((string) $record['jwks'], true);
            if (is_array($keySet)) {
                return $keySet;
            }
        }

        $metadataResponse = $this->httpClient->request('GET', self::METADATA_URL);
        if ($metadataResponse->statusCode < 200 || $metadataResponse->statusCode >= 300) {
            throw new TeamsIntegrationException('Bot OpenID metadata could not be loaded.');
        }
        $metadata = $metadataResponse->json();
        $jwksUri = (string) ($metadata['jwks_uri'] ?? '');
        $algorithms = isset($metadata['id_token_signing_alg_values_supported']) && is_array($metadata['id_token_signing_alg_values_supported'])
            ? array_values(array_map('strval', $metadata['id_token_signing_alg_values_supported']))
            : ['RS256'];
        if (!str_starts_with(strtolower($jwksUri), 'https://')) {
            throw new TeamsIntegrationException('Bot OpenID key URL must use HTTPS.');
        }

        $keysResponse = $this->httpClient->request('GET', $jwksUri);
        if ($keysResponse->statusCode < 200 || $keysResponse->statusCode >= 300) {
            throw new TeamsIntegrationException('Bot OpenID signing keys could not be loaded.');
        }
        $keys = $keysResponse->json();
        $keySet = [
            'keys' => is_array($keys['keys'] ?? null) ? $keys['keys'] : [],
            'algorithms' => $algorithms,
        ];
        $encoded = json_encode($keySet, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $now = date('Y-m-d H:i:s');
        if (is_array($record) && !empty($record['id'])) {
            $DB->update(self::TABLE, ['jwks' => $encoded, 'fetched_at' => $now], ['id' => 1]);
        } else {
            $DB->insert(self::TABLE, ['id' => 1, 'jwks' => $encoded, 'fetched_at' => $now]);
        }

        return $keySet;
    }

    private function findKey(array $keys, string $keyId): ?array
    {
        foreach ($keys as $key) {
            if (is_array($key) && (($key['kid'] ?? '') === $keyId || ($key['x5t'] ?? '') === $keyId)) {
                return $key;
            }
        }

        return null;
    }

    private function parts(string $token): array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3 || in_array('', $parts, true)) {
            throw new TeamsIntegrationException('Bot JWT format is invalid.');
        }

        return $parts;
    }

    private function decodeJson(string $encoded): array
    {
        try {
            $decoded = json_decode($this->base64UrlDecode($encoded), true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new TeamsIntegrationException('Bot JWT JSON is invalid.');
        }
        if (!is_array($decoded)) {
            throw new TeamsIntegrationException('Bot JWT JSON is invalid.');
        }

        return $decoded;
    }

    private function base64UrlDecode(string $value): string
    {
        $remainder = strlen($value) % 4;
        if ($remainder > 0) {
            $value .= str_repeat('=', 4 - $remainder);
        }
        $decoded = base64_decode(strtr($value, '-_', '+/'), true);
        if ($decoded === false) {
            throw new TeamsIntegrationException('Bot JWT base64 is invalid.');
        }

        return $decoded;
    }
}
