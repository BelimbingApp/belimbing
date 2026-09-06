#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Composed-application smoke test (belimbing#600).
 *
 * Nothing else proves, as one assertion, that the platform boots with every
 * pinned Domain mounted and exposes the surface the pins promise. Route
 * discovery refuses a collision (#570), the migration preflight refuses a
 * duplicate migration name at migrate time (#117), and the descriptor pins
 * immutable refs (#574, #582, #591) — but each fires on its own, in its own
 * run. This script composes the descriptor's domains at their exact refs,
 * boots the application once, and holds the result to a checked-in surface:
 *
 *   - the boot succeeds (a RouteCollisionException or a shared-table refusal
 *     surfaces here as a failed boot with its message);
 *   - the route table has exactly the expected count and contains every
 *     expected route name;
 *   - no migration basename appears in more than one migration directory
 *     across Base, Core, the mounted Domains and Extensions (the preflight's
 *     rule, applied without waiting for a migrate run).
 *
 * Domains are materialized from scripts/ci/domain-repos.json the way
 * domain-ci does (clone, detach at the ref). A mount that already exists is
 * accepted only when its HEAD is the pinned ref, so a stale local mount
 * cannot pass for the pin.
 *
 *   php scripts/ci/composed-smoke.php [--registry=<json>] [--surface=<json>]
 *       [--domains=people,people-connector] [--root=<platform checkout>]
 *       [--scan-only] [--print-surface] [--routes=<route:list json>]
 *
 * --scan-only skips materialization and the boot and runs the migration scan
 * alone, so a fixture tree can prove the duplicate rule without a network.
 * --print-surface boots and writes the observed surface as JSON to stdout
 * instead of judging it: the way to regenerate composed-surface.json after a
 * pin advances.
 * --routes judges a saved `route:list --json` table instead of booting, so
 * tests/ci/test-ci-scripts.sh can drive every surface guard (pin agreement,
 * mount at the pinned ref, route count, expected route names) against
 * fixtures without a network or a boot. Production runs never pass it.
 */
function fail(string $message): never
{
    fwrite(STDERR, "composed-smoke: {$message}\n");
    exit(1);
}

/** @return array<string, string> */
function options(array $argv): array
{
    $options = [
        'registry' => null,
        'surface' => null,
        'domains' => 'people,people-connector',
        'root' => dirname(__DIR__, 2),
        'routes' => null,
        'scan-only' => false,
        'print-surface' => false,
    ];

    foreach (array_slice($argv, 1) as $argument) {
        if ($argument === '--scan-only' || $argument === '--print-surface') {
            $options[substr($argument, 2)] = true;

            continue;
        }
        if (! preg_match('/^--([a-z-]+)=(.+)$/', $argument, $match) || ! array_key_exists($match[1], $options)) {
            fail("unknown argument {$argument}");
        }
        $options[$match[1]] = $match[2];
    }

    $options['root'] = rtrim((string) realpath((string) $options['root']), '/');
    $options['registry'] ??= $options['root'].'/scripts/ci/domain-repos.json';
    $options['surface'] ??= $options['root'].'/scripts/ci/composed-surface.json';

    return $options;
}

/** @return array<string, mixed> */
function readJson(string $path): array
{
    $contents = @file_get_contents($path);
    if ($contents === false) {
        fail("cannot read {$path}");
    }
    $data = json_decode($contents, true);
    if (! is_array($data)) {
        fail("{$path} is not valid JSON");
    }

    return $data;
}

function run(array $command, ?string $cwd = null): array
{
    $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $process = proc_open($command, $descriptors, $pipes, $cwd);
    if (! is_resource($process)) {
        fail('cannot start '.implode(' ', $command));
    }
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    return [proc_close($process), (string) $stdout, (string) $stderr];
}

/**
 * Clone each selected domain at its pinned ref, or accept an existing mount
 * whose HEAD is that ref.
 *
 * @return array<string, string> domain id => mount path
 */
function materialize(array $registry, array $domainIds, string $root): array
{
    $mounts = [];

    foreach ($domainIds as $id) {
        $domain = $registry['domains'][$id] ?? fail("descriptor has no domain [{$id}]");
        $ref = (string) ($domain['ref'] ?? '');
        if (preg_match('/^[0-9a-f]{40}$/', $ref) !== 1) {
            fail("{$id}.ref must be an immutable 40-character commit SHA");
        }
        $path = $root.'/'.trim((string) $domain['path'], '/');

        if (! is_dir($path)) {
            fwrite(STDERR, "composed-smoke: materializing {$domain['repo']}@{$ref} -> {$domain['path']}\n");
            [$code, , $error] = run(['git', 'clone', '--quiet', '--filter=blob:none', '--no-checkout', "https://github.com/{$domain['repo']}.git", $path]);
            if ($code !== 0) {
                fail("clone of {$domain['repo']} failed: {$error}");
            }
            [$code, , $error] = run(['git', '-C', $path, 'checkout', '--quiet', '--detach', $ref]);
            if ($code !== 0) {
                fail("checkout of {$domain['repo']}@{$ref} failed: {$error}");
            }
        }

        [$code, $head] = run(['git', '-C', $path, 'rev-parse', 'HEAD']);
        $head = trim($head);
        if ($code !== 0 || $head !== $ref) {
            fail("{$domain['path']} is mounted at {$head}, not the pinned {$ref}; unmount it or advance the pin");
        }

        $mounts[$id] = $path;
    }

    return $mounts;
}

/**
 * Migration basenames that appear in more than one migration directory. The
 * same rule ModuleMigrationDependencyChecker applies before a migrate run.
 *
 * @return array<string, list<string>>
 */
function duplicateMigrations(string $root): array
{
    $directories = array_merge(
        [$root.'/database/migrations'],
        glob($root.'/app/Base/*/Database/Migrations', GLOB_ONLYDIR) ?: [],
        glob($root.'/app/Core/*/Database/Migrations', GLOB_ONLYDIR) ?: [],
        glob($root.'/app/Domains/*/*/Database/Migrations', GLOB_ONLYDIR) ?: [],
        glob($root.'/app/Extensions/*/*/Database/Migrations', GLOB_ONLYDIR) ?: [],
    );

    $filesByName = [];
    foreach ($directories as $directory) {
        foreach (glob($directory.'/*_*.php') ?: [] as $file) {
            $filesByName[basename($file, '.php')][] = substr($file, strlen($root) + 1);
        }
    }
    ksort($filesByName);

    return array_filter($filesByName, fn (array $files): bool => count($files) > 1);
}

$options = options($argv);
$root = $options['root'];
$failures = [];

$duplicates = duplicateMigrations($root);
foreach ($duplicates as $name => $files) {
    $failures[] = "migration [{$name}] is defined more than once: ".implode(', ', $files);
}
$migrationCount = count(array_merge(...array_values(array_map(
    fn (string $directory): array => glob($directory.'/*_*.php') ?: [],
    array_merge([$root.'/database/migrations'], glob($root.'/app/{Base,Core}/*/Database/Migrations', GLOB_ONLYDIR | GLOB_BRACE) ?: [], glob($root.'/app/{Domains,Extensions}/*/*/Database/Migrations', GLOB_ONLYDIR | GLOB_BRACE) ?: []),
)) ?: [[]]));

if ($options['scan-only']) {
    fwrite(STDERR, sprintf("composed-smoke: %d migration(s) scanned, %d duplicate name(s)\n", $migrationCount, count($duplicates)));
    if ($failures !== []) {
        fail(implode("\n", $failures));
    }
    exit(0);
}

$registry = readJson($options['registry']);
$surface = readJson($options['surface']);
$domainIds = array_values(array_filter(array_map('trim', explode(',', $options['domains']))));
$mounts = materialize($registry, $domainIds, $root);

foreach ($domainIds as $id) {
    $expectedPin = (string) ($surface['pins'][$id] ?? '');
    if ($expectedPin !== (string) $registry['domains'][$id]['ref']) {
        $failures[] = "surface pins {$id} at [{$expectedPin}] but the descriptor pins [{$registry['domains'][$id]['ref']}]; regenerate scripts/ci/composed-surface.json";
    }
}

if ($options['routes'] !== null) {
    $routes = readJson($options['routes']);
} else {
    [$code, $stdout, $stderr] = run(['php', 'artisan', 'route:list', '--json'], $root);
    if ($code !== 0) {
        $message = trim($stderr) !== '' ? trim($stderr) : trim($stdout);
        fail("the composed application failed to boot:\n".$message);
    }
    $routes = json_decode($stdout, true);
    if (! is_array($routes)) {
        fail('route:list did not return JSON');
    }
}
$names = array_values(array_filter(array_map(fn (array $route): ?string => $route['name'] ?? null, $routes)));
sort($names);

if ($options['print-surface']) {
    echo json_encode([
        '_comment' => $surface['_comment'] ?? '',
        'pins' => array_combine($domainIds, array_map(fn (string $id): string => (string) $registry['domains'][$id]['ref'], $domainIds)),
        'route_count' => count($routes),
        'route_names' => array_values(array_filter($names, fn (string $name): bool => preg_match('/^(people\.|admin\.people-connector\.|admin\.integration\.)/', $name) === 1)),
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n";
    exit(0);
}

$expectedCount = (int) ($surface['route_count'] ?? -1);
if (count($routes) !== $expectedCount) {
    $failures[] = sprintf('route count is %d, expected %d', count($routes), $expectedCount);
}
$missing = array_values(array_diff((array) ($surface['route_names'] ?? []), $names));
if ($missing !== []) {
    $failures[] = 'expected route names missing from the composed table: '.implode(', ', $missing);
}

fwrite(STDERR, sprintf(
    "composed-smoke: %s; %d routes (%d named), %d migration(s), %d duplicate name(s)\n",
    implode(', ', array_map(fn (string $id): string => "{$id}@".substr((string) $registry['domains'][$id]['ref'], 0, 8), $domainIds)),
    count($routes),
    count($names),
    $migrationCount,
    count($duplicates),
));

if ($failures !== []) {
    fail(implode("\n", $failures));
}

echo "composed-smoke: ok\n";
