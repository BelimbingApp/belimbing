<?php

namespace App\Base\Database\DTO\Bridge;

use App\Base\Database\Enums\BridgeInstanceRole;
use App\Base\Database\Exceptions\BridgeTransportException;
use Carbon\CarbonImmutable;
use JsonException;

final readonly class BridgeReceiveGrantBundle
{
    public const FORMAT = 'belimbing-data-bridge/receive-grant/v1';

    public function __construct(
        public string $endpoint,
        /** @var list<string> */
        public array $endpoints,
        public string $grantId,
        public string $secret,
        public BridgeInstanceIdentity $expectedSource,
        public BridgeInstanceIdentity $target,
        public string $scope,
        public int $maxBytes,
        public string $expiresAt,
    ) {}

    public static function fromJson(string $json): self
    {
        try {
            $value = json_decode(trim($json), true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw BridgeTransportException::invalidGrantBundle();
        }

        if (! is_array($value)
            || ($value['format'] ?? null) !== self::FORMAT
            || ! is_string($value['endpoint'] ?? null)
            || ! is_array($value['endpoints'] ?? null)
            || ! is_string($value['grant_id'] ?? null)
            || preg_match('/^[0-9a-hjkmnp-tv-z]{26}$/', $value['grant_id']) !== 1
            || ! is_string($value['secret'] ?? null)
            || preg_match('/^[A-Za-z0-9_-]{43}$/', $value['secret']) !== 1
            || ! is_string($value['scope'] ?? null) || trim($value['scope']) === ''
            || ! is_int($value['max_bytes'] ?? null) || $value['max_bytes'] < 1
            || ! is_string($value['expires_at'] ?? null)) {
            throw BridgeTransportException::invalidGrantBundle();
        }

        $endpoint = trim($value['endpoint']);
        $endpoints = self::endpoints($value['endpoints'], $value['grant_id']);

        if (! in_array($endpoint, $endpoints, true)) {
            throw BridgeTransportException::invalidGrantBundle();
        }

        return new self(
            endpoint: $endpoint,
            endpoints: $endpoints,
            grantId: $value['grant_id'],
            secret: $value['secret'],
            expectedSource: self::identity($value['expected_source'] ?? null),
            target: self::identity($value['target'] ?? null),
            scope: trim($value['scope']),
            maxBytes: $value['max_bytes'],
            expiresAt: self::expiry($value['expires_at']),
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'format' => self::FORMAT,
            'endpoint' => $this->endpoint,
            'endpoints' => $this->endpoints,
            'grant_id' => $this->grantId,
            'secret' => $this->secret,
            'expected_source' => $this->expectedSource->toArray(),
            'target' => $this->target->toArray(),
            'scope' => $this->scope,
            'max_bytes' => $this->maxBytes,
            'expires_at' => $this->expiresAt,
        ];
    }

    public function toJson(): string
    {
        return json_encode($this->toArray(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    public function isExpired(): bool
    {
        return CarbonImmutable::parse($this->expiresAt, 'UTC')->isPast();
    }

    public function usingEndpoint(string $endpoint): self
    {
        $endpoint = trim($endpoint);

        if (! in_array($endpoint, $this->endpoints, true)) {
            throw BridgeTransportException::invalidGrantBundle();
        }

        return new self(
            endpoint: $endpoint,
            endpoints: $this->endpoints,
            grantId: $this->grantId,
            secret: $this->secret,
            expectedSource: $this->expectedSource,
            target: $this->target,
            scope: $this->scope,
            maxBytes: $this->maxBytes,
            expiresAt: $this->expiresAt,
        );
    }

    private static function identity(mixed $value): BridgeInstanceIdentity
    {
        if (! is_array($value)
            || ! is_string($value['id'] ?? null) || trim($value['id']) === ''
            || ! is_string($value['name'] ?? null)
            || ! is_string($value['role'] ?? null)) {
            throw BridgeTransportException::invalidGrantBundle();
        }

        $role = BridgeInstanceRole::tryFrom($value['role']);

        if ($role === null) {
            throw BridgeTransportException::invalidGrantBundle();
        }

        return new BridgeInstanceIdentity(trim($value['id']), trim($value['name']) ?: trim($value['id']), $role);
    }

    /** @return list<string> */
    private static function endpoints(array $values, string $grantId): array
    {
        if ($values === [] || count($values) > 5) {
            throw BridgeTransportException::invalidGrantBundle();
        }

        $expectedPath = '/data-bridge/receive/'.$grantId;
        $endpoints = [];

        foreach ($values as $value) {
            if (! is_string($value)) {
                throw BridgeTransportException::invalidGrantBundle();
            }

            $endpoint = trim($value);
            $parts = parse_url($endpoint);

            if (filter_var($endpoint, FILTER_VALIDATE_URL) === false
                || ! is_array($parts)
                || ($parts['scheme'] ?? null) !== 'https'
                || ! is_string($parts['host'] ?? null)
                || ! str_ends_with((string) ($parts['path'] ?? ''), $expectedPath)
                || isset($parts['user'])
                || isset($parts['pass'])
                || isset($parts['query'])
                || isset($parts['fragment'])) {
                throw BridgeTransportException::invalidGrantBundle();
            }

            $endpoints[] = $endpoint;
        }

        return array_values(array_unique($endpoints));
    }

    private static function expiry(string $value): string
    {
        try {
            return CarbonImmutable::parse($value, 'UTC')->toIso8601String();
        } catch (\Throwable) {
            throw BridgeTransportException::invalidGrantBundle();
        }
    }
}
