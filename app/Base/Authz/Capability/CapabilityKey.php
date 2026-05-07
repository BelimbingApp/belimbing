<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Base\Authz\Capability;

use InvalidArgumentException;

final class CapabilityKey
{
    /**
     * Capability key pattern: <domain>.<resource-path>.<action>.
     *
     * Segments are lowercase words with optional numbers and hyphens.
     * The first segment is the domain, the last segment is the action, and
     * any middle segments form the resource path.
     */
    private const SEGMENT = '[a-z][a-z0-9]*(?:-[a-z0-9]+)*';

    private const PATTERN = '/^'.self::SEGMENT.'(?:\.'.self::SEGMENT.'){2,}$/';

    /**
     * Determine whether a capability key uses the BLB grammar.
     */
    public static function isValid(string $key): bool
    {
        return preg_match(self::PATTERN, $key) === 1;
    }

    /**
     * Parse a capability key into [domain, resource, action].
     *
     * @return array{domain: string, resource: string, action: string}
     */
    public static function parse(string $key): array
    {
        if (! self::isValid($key)) {
            throw new InvalidArgumentException("Invalid capability key [$key].");
        }

        $segments = explode('.', $key);
        $domain = array_shift($segments);
        $action = array_pop($segments);

        return [
            'domain' => $domain,
            'resource' => implode('.', $segments),
            'action' => $action,
        ];
    }

    /**
     * Build and validate a capability key from parts.
     */
    public static function fromParts(string $domain, string $resource, string $action): string
    {
        $key = strtolower($domain.'.'.$resource.'.'.$action);

        if (! self::isValid($key)) {
            throw new InvalidArgumentException("Invalid capability key [$key].");
        }

        return $key;
    }
}
