#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Resolve the cross-domain repositories a domain needs in CI.
 *
 * A domain repo (e.g. blb-people) is checked out at its contract path inside a
 * platform checkout. Its modules ship together, and core/* dependencies
 * ship in the platform. Anything else a manifest declares under
 * `extra.blb.requires-modules` lives in another domain repo that must also be
 * placed before the test suite can boot.
 *
 * This script scans the placed domain's manifests, computes those missing
 * cross-domain repos using scripts/ci/domain-repos.json, and prints one
 * `<owner/repo>\t<checkout-path>` line per repo to stdout for the workflow to
 * clone. A human-readable summary goes to stderr. It exits non-zero when a
 * required module has no registry entry — a missing edge is a real failure, not
 * something to paper over.
 */

/**
 * @return array{domain-path: string, registry: string}
 */
function parseArguments(array $argv): array
{
    $domainPath = null;
    $registry = dirname(__DIR__).'/ci/domain-repos.json';

    for ($index = 1; $index < count($argv); $index++) {
        $argument = $argv[$index];

        if (str_starts_with($argument, '--domain-path=')) {
            $domainPath = substr($argument, strlen('--domain-path='));

            continue;
        }

        if (str_starts_with($argument, '--registry=')) {
            $registry = substr($argument, strlen('--registry='));
        }
    }

    if ($domainPath === null || $domainPath === '') {
        fwrite(STDERR, "compose-domain: --domain-path=<path> is required\n");
        exit(2);
    }

    return ['domain-path' => rtrim($domainPath, '/'), 'registry' => $registry];
}

/**
 * @return array<string, array{repo: string, path: string}>
 */
function loadRegistry(string $registryPath): array
{
    $contents = @file_get_contents($registryPath);
    if ($contents === false) {
        fwrite(STDERR, "compose-domain: cannot read registry at {$registryPath}\n");
        exit(2);
    }

    $data = json_decode($contents, true);
    if (! is_array($data)) {
        fwrite(STDERR, "compose-domain: registry at {$registryPath} is not valid JSON\n");
        exit(2);
    }

    $map = [];
    foreach ((array) ($data['domains'] ?? []) as $key => $entry) {
        if (is_string($key) && is_array($entry) && isset($entry['repo'], $entry['path'])) {
            $map[$key] = ['repo' => (string) $entry['repo'], 'path' => (string) $entry['path']];
        }
    }

    return $map;
}

/**
 * Collect every required-module id declared by the manifests under a domain path.
 *
 * @return list<string>
 */
function requiredModuleIds(string $domainPath): array
{
    $ids = [];

    foreach ((array) glob($domainPath.'/*/composer.json') as $composerPath) {
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

/**
 * Collect the module ids the domain under test itself provides. A module
 * requiring a sibling module in the same repository needs no clone and no
 * registry entry - the code is already sitting next to it.
 *
 * @return list<string>
 */
function providedModuleIds(string $domainPath): array
{
    $ids = [];

    foreach ((array) glob($domainPath.'/*/composer.json') as $composerPath) {
        $data = json_decode((string) @file_get_contents($composerPath), true);
        $module = is_array($data) ? ($data['extra']['blb']['module'] ?? null) : null;
        if (is_string($module) && $module !== '') {
            $ids[] = $module;
        }
    }

    return array_values(array_unique($ids));
}

$options = parseArguments($argv);
$registry = loadRegistry($options['registry']);
$providedIds = providedModuleIds($options['domain-path']);
$requiredIds = array_values(array_diff(requiredModuleIds($options['domain-path']), $providedIds));

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
    fwrite(STDERR, 'compose-domain: no registry entry for required module(s): '.implode(', ', $missing)."\n");
    exit(1);
}

if ($toClone === []) {
    fwrite(STDERR, "compose-domain: no cross-domain dependencies to clone for {$options['domain-path']}\n");
    exit(0);
}

foreach ($toClone as $path => $repo) {
    fwrite(STDERR, "compose-domain: will clone {$repo} -> {$path}\n");
    echo $repo."\t".$path."\n";
}
