<?php

namespace App\Base\Database\DTO\DataShare;

use App\Base\Database\Enums\DataShareInstanceRole;
use App\Base\Database\Exceptions\DataShareTransportException;
use Carbon\CarbonImmutable;
use JsonException;

final readonly class DataShareTransferOfferBundle
{
    public const FORMAT = 'belimbing-data-share/offer/v1';

    /** @param list<string> $endpoints @param array{tables: int, records: int} $counts */
    public function __construct(
        public string $endpoint,
        public array $endpoints,
        public string $offerId,
        public string $secret,
        public DataShareInstanceIdentity $source,
        public string $scope,
        public string $packageId,
        public string $packageSha256,
        public int $bytes,
        public array $counts,
        public string $expiresAt,
    ) {}

    public static function fromJson(string $json): self
    {
        try {
            $value = json_decode(trim($json), true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw DataShareTransportException::invalidOfferBundle();
        }

        if (! is_array($value)
            || ($value['format'] ?? null) !== self::FORMAT
            || ! is_string($value['endpoint'] ?? null)
            || ! is_array($value['endpoints'] ?? null)
            || ! self::isUlid($value['offer_id'] ?? null)
            || ! is_string($value['secret'] ?? null)
            || preg_match('/^[A-Za-z0-9_-]{43}$/', $value['secret']) !== 1
            || ! is_string($value['scope'] ?? null) || trim($value['scope']) === ''
            || ! self::isUlid($value['package_id'] ?? null)
            || ! is_string($value['package_sha256'] ?? null)
            || preg_match('/^[a-f0-9]{64}$/', $value['package_sha256']) !== 1
            || ! is_int($value['bytes'] ?? null) || $value['bytes'] < 1
            || ! is_array($value['counts'] ?? null)
            || ! is_int($value['counts']['tables'] ?? null) || $value['counts']['tables'] < 1
            || ! is_int($value['counts']['records'] ?? null) || $value['counts']['records'] < 0
            || ! is_string($value['expires_at'] ?? null)) {
            throw DataShareTransportException::invalidOfferBundle();
        }

        $endpoint = trim($value['endpoint']);
        $endpoints = self::endpoints($value['endpoints'], $value['offer_id']);

        if (! in_array($endpoint, $endpoints, true)) {
            throw DataShareTransportException::invalidOfferBundle();
        }

        return new self(
            endpoint: $endpoint,
            endpoints: $endpoints,
            offerId: $value['offer_id'],
            secret: $value['secret'],
            source: self::identity($value['source'] ?? null),
            scope: trim($value['scope']),
            packageId: $value['package_id'],
            packageSha256: $value['package_sha256'],
            bytes: $value['bytes'],
            counts: ['tables' => $value['counts']['tables'], 'records' => $value['counts']['records']],
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
            'offer_id' => $this->offerId,
            'secret' => $this->secret,
            'source' => $this->source->toArray(),
            'scope' => $this->scope,
            'package_id' => $this->packageId,
            'package_sha256' => $this->packageSha256,
            'bytes' => $this->bytes,
            'counts' => $this->counts,
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
            throw DataShareTransportException::invalidOfferBundle();
        }

        return new self(
            $endpoint,
            $this->endpoints,
            $this->offerId,
            $this->secret,
            $this->source,
            $this->scope,
            $this->packageId,
            $this->packageSha256,
            $this->bytes,
            $this->counts,
            $this->expiresAt,
        );
    }

    private static function isUlid(mixed $value): bool
    {
        return is_string($value) && preg_match('/^[0-9a-hjkmnp-tv-z]{26}$/', $value) === 1;
    }

    private static function identity(mixed $value): DataShareInstanceIdentity
    {
        if (! is_array($value)
            || ! is_string($value['id'] ?? null) || trim($value['id']) === ''
            || ! is_string($value['name'] ?? null)
            || ! is_string($value['role'] ?? null)
            || DataShareInstanceRole::tryFrom($value['role']) === null) {
            throw DataShareTransportException::invalidOfferBundle();
        }

        return new DataShareInstanceIdentity(
            trim($value['id']),
            trim($value['name']) ?: trim($value['id']),
            DataShareInstanceRole::from($value['role']),
        );
    }

    /** @return list<string> */
    private static function endpoints(array $values, string $offerId): array
    {
        if ($values === [] || count($values) > 5) {
            throw DataShareTransportException::invalidOfferBundle();
        }

        $expectedPath = '/data-share/offers/'.$offerId;
        $endpoints = [];

        foreach ($values as $value) {
            $endpoint = is_string($value) ? trim($value) : '';
            $parts = parse_url($endpoint);

            if (filter_var($endpoint, FILTER_VALIDATE_URL) === false
                || ! is_array($parts)
                || ($parts['scheme'] ?? null) !== 'https'
                || ! is_string($parts['host'] ?? null)
                || ($parts['path'] ?? null) !== $expectedPath
                || isset($parts['user'])
                || isset($parts['pass'])
                || isset($parts['query'])
                || isset($parts['fragment'])) {
                throw DataShareTransportException::invalidOfferBundle();
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
            throw DataShareTransportException::invalidOfferBundle();
        }
    }
}
