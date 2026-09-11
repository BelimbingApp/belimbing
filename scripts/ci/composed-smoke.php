#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Composed-application smoke test (belimbing#600, reshaped by #940).
 *
 * Nothing else proves, as one assertion, that the platform boots with every
 * controlled Domain mounted at once. Route discovery refuses a collision
 * (#570) and the migration preflight refuses a duplicate migration name at
 * migrate time (#117), but each fires on its own, in its own run — and a
 * collision between two Domains is invisible to either Domain's own CI,
 * because each one is perfectly fine alone. This script mounts them together,
 * boots the application once, and reports what refused.
 *
 *   - the boot succeeds (a RouteCollisionException or a shared-table refusal
 *     surfaces here as a failed boot with its message);
 *   - no migration basename appears in more than one migration directory
 *     across Base, Core, the mounted Domains and Extensions (the preflight's
 *     rule, applied without waiting for a migrate run).
 *
 * Domains are materialized at their `main`. There is deliberately no pinned
 * ref and no checked-in route surface: this asserts that the composed world
 * works, not that a Domain has a particular set of routes. A Domain's own
 * route inventory is that Domain's business and belongs in its own suite
 * (#940). The cost of composing at `main` is stated plainly: a red run here
 * does not tell you whether the platform or a Domain caused it, and it cannot
 * be re-run against a fixed past state. The gain is that nothing goes stale.
 *
 *   php scripts/ci/composed-smoke.php [--registry=<json>] [--root=<platform checkout>]
 *       [--domains=<optional comma-separated subset>] [--scan-only]
 *
 * --scan-only skips materialization and the boot and runs the migration scan
 * alone, so a fixture tree can prove the duplicate rule without a network.
 */
require_once __DIR__.'/domain-registry.php';

function fail(string $message): never
{
    fwrite(STDERR, "composed-smoke: {$message}\n");
    exit(1);
}

/** @return array<string, string|bool|null> */
function options(array $argv): array
{
    $options = [
        'registry' => null,
        'domains' => null,
        'root' => dirname(__DIR__, 2),
        'scan-only' => false,
    ];

    foreach (array_slice($argv, 1) as $argument) {
        if ($argument === '--scan-only') {
            $options['scan-only'] = true;

            continue;
        }
        if (! preg_match('/^--([a-z-]+)=(.+)$/', $argument, $match)) {
            fail("unknown argument {$argument}");
        }
        if (! array_key_exists($match[1], $options)) {
            fail("unknown argument {$argument}");
        }
        $options[$match[1]] = $match[2];
    }

    $options['root'] = rtrim((string) realpath((string) $options['root']), '/');

    return $options;
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
 * Clone each selected Domain at its default branch, or accept an existing
 * mount as it stands. Candidates are tried in remote order (origin's owner,
 * then upstream's), so a fork that hosts only some of its Domains still
 * resolves the rest from the repository it forked.
 *
 * @param  array<string, array{repo: string, path: string, repo_candidates: list<string>}>  $domains
 * @return array<string, string> domain id => mount path
 */
function materialize(array $domains, array $domainIds, string $root): array
{
    $mounts = [];

    foreach ($domainIds as $id) {
        $domain = $domains[$id] ?? fail("descriptor does not list domain [{$id}]");
        $path = $root.'/'.trim($domain['path'], '/');

        if (! is_dir($path)) {
            $cloned = false;
            $errors = [];
            foreach ($domain['repo_candidates'] as $repo) {
                fwrite(STDERR, "composed-smoke: materializing {$repo} -> {$domain['path']}\n");
                [$code, , $error] = run(['git', 'clone', '--quiet', '--depth', '1', "https://github.com/{$repo}.git", $path]);
                if ($code === 0) {
                    $cloned = true;

                    break;
                }
                $errors[] = "{$repo}: ".trim($error);
            }
            if (! $cloned) {
                fail("clone of {$id} failed:\n  ".implode("\n  ", $errors));
            }
        }

        [, $head] = run(['git', '-C', $path, 'rev-parse', 'HEAD']);
        fwrite(STDERR, sprintf("composed-smoke: %s mounted at %s\n", $id, substr(trim($head), 0, 8) ?: 'unknown'));

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
$mounted = [];

if (! $options['scan-only']) {
    $registry = domainRegistry($options['registry'], $root);
    $domainIds = $options['domains'] === null
        ? array_keys($registry['domains'])
        : array_values(array_filter(array_map('trim', explode(',', (string) $options['domains']))));
    $mounted = array_keys(materialize($registry['domains'], $domainIds, $root));
}

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

[$code, $stdout, $stderr] = run(['php', 'artisan', 'route:list', '--json'], $root);
if ($code !== 0) {
    $message = trim($stderr) !== '' ? trim($stderr) : trim($stdout);
    fail("the composed application failed to boot:\n".$message);
}
$routes = json_decode($stdout, true);
if (! is_array($routes)) {
    fail('route:list did not return JSON');
}
$named = array_values(array_filter(array_map(fn (array $route): ?string => $route['name'] ?? null, $routes)));

fwrite(STDERR, sprintf(
    "composed-smoke: %s; %d routes (%d named), %d migration(s), %d duplicate name(s)\n",
    implode(', ', $mounted),
    count($routes),
    count($named),
    $migrationCount,
    count($duplicates),
));

if ($failures !== []) {
    fail(implode("\n", $failures));
}

echo "composed-smoke: ok\n";
