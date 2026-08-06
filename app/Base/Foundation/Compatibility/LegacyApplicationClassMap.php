<?php

namespace App\Base\Foundation\Compatibility;

/**
 * Bounded namespace compatibility for executable references authored before
 * ADR 0001. New code must use only the canonical namespace returned here.
 */
final class LegacyApplicationClassMap
{
    /** @var array<string, string> */
    private const array PREFIXES = [
        'App\\Modules\\Core\\' => 'App\\Core\\',
        'App\\Modules\\' => 'App\\Domains\\',
        'Extensions\\' => 'App\\Extensions\\',
    ];

    public static function canonical(string $class): string
    {
        foreach (self::PREFIXES as $legacyPrefix => $canonicalPrefix) {
            if (str_starts_with($class, $legacyPrefix)) {
                return $canonicalPrefix.substr($class, strlen($legacyPrefix));
            }
        }

        return $class;
    }

    /**
     * Canonical identity followed by any equivalent pre-cutover identity.
     *
     * @return list<string>
     */
    public static function equivalents(string $class): array
    {
        $canonical = self::canonical($class);
        $equivalents = [$canonical];

        foreach (self::PREFIXES as $legacyPrefix => $canonicalPrefix) {
            if (str_starts_with($canonical, $canonicalPrefix)) {
                $equivalents[] = $legacyPrefix.substr($canonical, strlen($canonicalPrefix));

                break;
            }
        }

        return $equivalents;
    }
}
