#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Resolve the cross-domain Distribution Bundle repos a module needs in CI.
 *
 * A module repo (e.g. blb-people) is checked out at its contract path inside a
 * platform checkout. Its sibling modules ship in the same repo, and core/* deps
 * ship in the platform. Anything else a manifest declares under
 * `extra.blb.requires-modules` lives in another domain repo that must also be
 * placed before the test suite can boot.
 *
 * This script scans the placed module's manifests, computes those missing
 * cross-domain repos using scripts/ci/module-repos.json, and prints one
 * `<owner/repo>\t<checkout-path>` line per repo to stdout for the workflow to
 * clone. A human-readable summary goes to stderr. It exits non-zero when a
 * required module has no registry entry — a missing edge is a real failure, not
 * something to paper over.
 */

/**
 * @return array{module-path: string, registry: string}
 */
function parseArguments(array $argv): array
{
    $modulePath = null;
    $registry = dirname(__DIR__).'/ci/module-repos.json';

    for ($index = 1; $index < count($argv); $index++) {
        $argument = $argv[$index];

        if (str_starts_with($argument, '--module-path=')) {
            $modulePath = substr($argument, strlen('--module-path='));

            continue;
        }

        if (str_starts_with($argument, '--registry=')) {
            $registry = substr($argument, strlen('--registry='));

            continue;
        }
    }

    if ($modulePath === null || $modulePath === '') {
        fwrite(STDERR, "compose-module: --module-path=<path> is required\n");
        exit(2);
    }

    return ['module-path' => rtrim($modulePath, '/'), 'registry' => $registry];
}

/**
 * @return array<string, array{repo: string, path: string}>
 */
function loadRegistry(string $registryPath): array
{
    $contents = @file_get_contents($registryPath);
    if ($contents === false) {
        fwrite(STDERR, "compose-module: cannot read registry at {$registryPath}\n");
        exit(2);
    }

    $data = json_decode($contents, true);
    if (! is_array($data)) {
        fwrite(STDERR, "compose-module: registry at {$registryPath} is not valid JSON\n");
        exit(2);
    }

    $map = [];
    foreach ((array) ($data['modules'] ?? []) as $key => $entry) {
        if (is_string($key) && is_array($entry) && isset($entry['repo'], $entry['path'])) {
            $map[$key] = ['repo' => (string) $entry['repo'], 'path' => (string) $entry['path']];
        }
    }

    return $map;
}

/**
 * Collect every required-module id declared by the manifests under a module path.
 *
 * @return list<string>
 */
function requiredModuleIds(string $modulePath): array
{
    $ids = [];

    foreach ((array) glob($modulePath.'/*/composer.json') as $composerPath) {
        $data = json_decode((string) @file_get_contents($composerPath), true);
        $requires = is_array($data) ? ($data['extra']['blb']['requires-modules'] ?? null) : null;
        if (! is_array($requires)) {
            continue;
        }

        foreach (array_keys($requires) as $required) {
            if (is_string($required) && $required !== '') {
                $ids[] = $required;
            }
        }
    }

    return array_values(array_unique($ids));
}

$options = parseArguments($argv);
$registry = loadRegistry($options['registry']);
$requiredIds = requiredModuleIds($options['module-path']);

$toClone = [];
$missing = [];

foreach ($requiredIds as $id) {
    $domain = strstr($id, '/', true);
    if ($domain === false || $domain === '') {
        $domain = $id;
    }

    // Core ships in the platform checkout; never cloned.
    if ($domain === 'core') {
        continue;
    }

    if (! isset($registry[$domain])) {
        $missing[] = $id;

        continue;
    }

    $entry = $registry[$domain];

    // Already present (the module under test, or an earlier resolved repo).
    if (is_dir($entry['path'])) {
        continue;
    }

    $toClone[$entry['path']] = $entry['repo'];
}

if ($missing !== []) {
    fwrite(STDERR, 'compose-module: no registry entry for required module(s): '.implode(', ', $missing)."\n");
    exit(1);
}

if ($toClone === []) {
    fwrite(STDERR, "compose-module: no cross-domain dependencies to clone for {$options['module-path']}\n");
    exit(0);
}

foreach ($toClone as $path => $repo) {
    fwrite(STDERR, "compose-module: will clone {$repo} -> {$path}\n");
    echo $repo."\t".$path."\n";
}
