<?php

/**
 * Tenant isolation: not applicable — no tenant-owned data is read. This file
 * only compares filesystem view roots to Tailwind `@source` globs and Vite
 * blade refresh paths.
 */

use App\Base\Foundation\ApplicationTopology;
use Tests\TestCase;

uses(TestCase::class);

/**
 * @return list<string>
 */
function tailwindSourceGlobs(): array
{
    $css = file_get_contents(base_path('resources/app.css'));
    expect($css)->not->toBeFalse();

    preg_match_all("/@source\\s+'([^']+)'/", (string) $css, $matches);

    $globs = [];
    foreach ($matches[1] as $raw) {
        // resources/app.css paths are relative to resources/.
        if (str_starts_with($raw, './')) {
            $globs[] = 'resources/'.substr($raw, 2);
        } elseif (str_starts_with($raw, '../')) {
            $globs[] = substr($raw, 3);
        } else {
            $globs[] = $raw;
        }
    }

    return $globs;
}

/**
 * @return list<string>
 */
function viteBladeRefreshViewRoots(): array
{
    $vite = file_get_contents(base_path('vite.config.js'));
    expect($vite)->not->toBeFalse();

    preg_match_all(
        "/'((?:resources\\/core\\/views|app\\/(?:Core|Domains|Extensions)\\/[^']+\\/Views)\\/\\*\\*\\/\\*\\.blade\\.php)'/",
        (string) $vite,
        $matches,
    );

    return array_values(array_unique(array_map(
        static fn (string $path): string => preg_replace('#/\*\*/\*\.blade\.php$#', '', $path) ?? $path,
        $matches[1],
    )));
}

/**
 * @return list<string>
 */
function onDiskModuleViewRoots(): array
{
    $patterns = [
        ApplicationTopology::coreModulePattern('Views'),
        ApplicationTopology::domainModulePattern('Views'),
        ApplicationTopology::extensionModulePattern('Views'),
    ];

    $roots = [];
    foreach ($patterns as $pattern) {
        foreach ((array) glob($pattern, GLOB_ONLYDIR) as $absolute) {
            $roots[] = str_replace('\\', '/', substr($absolute, strlen(base_path()) + 1));
        }
    }

    sort($roots);

    return array_values(array_unique($roots));
}

function pathMatchesAnyGlob(string $path, array $globs): bool
{
    foreach ($globs as $glob) {
        if (fnmatch($glob, $path, FNM_PATHNAME)) {
            return true;
        }
    }

    return false;
}

it('covers every on-disk Core, Domain, and Extension Views root with an @source glob', function (): void {
    $probe = base_path(ApplicationTopology::relativePathUnder(
        ApplicationTopology::DOMAINS,
        'ZzProbe',
        'Probe',
        'Views',
    ));
    mkdir($probe, 0777, true);

    try {
        $globs = tailwindSourceGlobs();
        $roots = onDiskModuleViewRoots();

        expect($roots)->toContain('app/Domains/ZzProbe/Probe/Views');

        $uncovered = array_values(array_filter(
            $roots,
            static fn (string $root): bool => ! pathMatchesAnyGlob($root, $globs),
        ));

        expect($uncovered)->toBe([]);
    } finally {
        @rmdir($probe);
        @rmdir(dirname($probe));
        @rmdir(dirname($probe, 2));
    }
});

it('covers every domain-repos.json path with an @source glob for its module Views', function (): void {
    $registry = json_decode((string) file_get_contents(base_path('scripts/ci/domain-repos.json')), true);
    expect($registry)->toBeArray();

    $globs = tailwindSourceGlobs();

    foreach ((array) ($registry['domains'] ?? []) as $entry) {
        expect($entry)->toBeArray()->and($entry['path'] ?? null)->toBeString();
        $example = rtrim((string) $entry['path'], '/').'/Example/Views';
        expect(pathMatchesAnyGlob($example, $globs))
            ->toBeTrue("registry path {$entry['path']} does not match @source for {$example}");
    }
});

it('keeps vite bladeRefreshPaths and @source on the same root families', function (): void {
    $sources = tailwindSourceGlobs();
    $viteRoots = viteBladeRefreshViewRoots();

    $requiredFamilies = [
        'app/Core/*/Views',
        'app/Domains/*/*/Views',
        'app/Extensions/*/*/Views',
    ];

    foreach ($requiredFamilies as $family) {
        expect($sources)->toContain($family);
        expect($viteRoots)->toContain($family);
    }

    expect($viteRoots)->toContain('resources/core/views');
    expect($sources)->toContain('resources/core/views');
});
