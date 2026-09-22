<?php

namespace App\Core\AI\Values;

use App\Core\AI\Exceptions\InvalidSessionIdException;
use DateTimeImmutable;
use DateTimeZone;

/**
 * A validated chat session identifier safe to use as one path component.
 */
final readonly class SessionId
{
    /**
     * Current timestamp-plus-random IDs and the two formats previously emitted in production.
     */
    public const ROUTE_PATTERN = '(?:[0-9]{8}-[0-9]{6}(?:-[a-z0-9]{6})?|[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12})';

    private const VALIDATION_PATTERN = '/\A'.self::ROUTE_PATTERN.'\z/';

    private function __construct(public string $value) {}

    public static function fromString(string $value): self
    {
        if (! self::isValid($value)) {
            throw new InvalidSessionIdException;
        }

        return new self($value);
    }

    public static function isValid(string $value): bool
    {
        if (preg_match(self::VALIDATION_PATTERN, $value) !== 1) {
            return false;
        }

        if (strlen($value) === 36) {
            return true;
        }

        $timestamp = substr($value, 0, 15);
        $parsed = DateTimeImmutable::createFromFormat('!Ymd-His', $timestamp, new DateTimeZone('UTC'));

        return $parsed !== false && $parsed->format('Ymd-His') === $timestamp;
    }
}
