<?php

namespace App\Base\Foundation\Services;

/**
 * Deploy-local domain state: which installed domains are disabled.
 *
 * A disabled domain keeps its checkout (code stays on disk, its tables
 * stay claimed) but is excluded from every discovery contract — providers,
 * routes, menus, Livewire components, settings, authz, audit, migrations,
 * seeders. State lives in storage/, never inside the nested domain repo,
 * so toggling it dirties no git working tree and survives re-clones.
 *
 * Static by necessity: ProviderRegistry consults it from
 * bootstrap/providers.php, before the container offers much else.
 */
class DomainState
{
    private const STATE_FILE = 'app/blb/disabled-domains.json';

    /** @var list<string>|null */
    private static ?array $disabledCache = null;

    /**
     * mtime of the state file the cache was read from. Octane workers are
     * long-lived, so the cache must notice when another worker (or a shell)
     * rewrites the file.
     */
    private static ?int $cacheMtime = null;

    private static ?string $statePathOverride = null;

    /**
     * Domains currently disabled (PascalCase directory names).
     *
     * @return list<string>
     */
    public static function disabled(): array
    {
        $path = self::statePath();

        clearstatcache(false, $path);
        $mtime = null;

        if (is_file($path)) {
            $mtime = filemtime($path) ?: null;
        }

        if (self::$disabledCache !== null && self::$cacheMtime === $mtime) {
            return self::$disabledCache;
        }

        self::$cacheMtime = $mtime;

        if ($mtime === null) {
            return self::$disabledCache = [];
        }

        $decoded = json_decode((string) file_get_contents($path), true);

        if (is_array($decoded)) {
            $decodedDisabled = $decoded['disabled'] ?? [];
        } else {
            $decodedDisabled = [];
        }

        $disabled = array_values(array_filter(
            $decodedDisabled,
            fn ($domain): bool => is_string($domain) && $domain !== '',
        ));

        sort($disabled);

        return self::$disabledCache = $disabled;
    }

    public static function isDisabled(string $domain): bool
    {
        return in_array($domain, self::disabled(), true);
    }

    public static function disable(string $domain): void
    {
        self::persist(array_unique([...self::disabled(), $domain]));
    }

    public static function enable(string $domain): void
    {
        self::persist(array_filter(
            self::disabled(),
            fn (string $candidate): bool => $candidate !== $domain,
        ));
    }

    /**
     * Drop discovery paths that belong to disabled domains.
     *
     * Every discovery service that globs app/Modules passes its results
     * through here, so one state file silences a domain everywhere.
     *
     * @param  list<string>  $paths
     * @return list<string>
     */
    public static function filterPaths(array $paths): array
    {
        $disabled = self::disabled();

        if ($disabled === []) {
            return $paths;
        }

        $prefixes = array_map(
            fn (string $domain): string => str_replace('\\', '/', app_path('Modules/'.$domain)).'/',
            $disabled,
        );

        return array_values(array_filter(
            $paths,
            function (string $path) use ($prefixes): bool {
                $normalized = str_replace('\\', '/', $path);

                foreach ($prefixes as $prefix) {
                    if (str_starts_with($normalized, $prefix)) {
                        return false;
                    }
                }

                return true;
            },
        ));
    }

    /**
     * Point at an alternate state file (tests). Null restores the default.
     */
    public static function useStatePath(?string $path): void
    {
        self::$statePathOverride = $path;
        self::$disabledCache = null;
        self::$cacheMtime = null;
    }

    /**
     * @param  array<int, string>  $disabled
     */
    private static function persist(array $disabled): void
    {
        $disabled = array_values($disabled);
        sort($disabled);

        $path = self::statePath();
        $directory = dirname($path);

        if (! is_dir($directory)) {
            mkdir($directory, 0755, recursive: true);
        }

        file_put_contents($path, json_encode(
            ['disabled' => $disabled],
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
        ).PHP_EOL);

        clearstatcache(false, $path);
        self::$disabledCache = $disabled;
        self::$cacheMtime = filemtime($path) ?: null;
    }

    private static function statePath(): string
    {
        if (self::$statePathOverride !== null) {
            return self::$statePathOverride;
        }

        return storage_path(self::STATE_FILE);
    }
}
