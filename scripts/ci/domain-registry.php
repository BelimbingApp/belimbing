#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * The one place a Domain's CI identity is derived (belimbing#940).
 *
 * A Domain's repository, mount path and Sonar project key are the Domain id
 * with a fixed prefix or a case change — `people-connector` is
 * `<owner>/blb-people-connector` at `app/Domains/PeopleConnector` with Sonar
 * key `<owner>_blb-people-connector`. That is a rule, so the descriptor lists
 * ids and this script applies the rule. Adding a Domain that follows the
 * convention needs no change here and no change to the callers.
 *
 * The owner is not hardcoded: it comes from this checkout's own git remotes,
 * `origin` first and then `upstream`. A clone of the canonical repository
 * resolves to the canonical org; a fork resolves to the fork's owner and falls
 * back to whatever `upstream` points at, which is where the Domains it does
 * not host itself will be. `repo_candidates` is that ordered list; `repo` is
 * its first entry. Set BLB_DOMAIN_OWNERS (comma-separated) to override, which
 * is how a checkout with no remotes at all — a tarball, a test fixture — says
 * who owns its Domains.
 *
 * There are deliberately no commit pins. CI composes Domains at their `main`.
 * A pin buys a repeatable build and nothing else: the guards that refuse a
 * collision between Domains (RouteCollisionException, TableRegistry,
 * IncubatingSchemaConflictException) live in the application and fire at boot
 * wherever it starts. Pins bought repeatability at the cost of a standing
 * maintenance chore; #940 records that trade and this file is the result.
 *
 *   php scripts/ci/domain-registry.php --json          resolved registry as JSON
 *   php scripts/ci/domain-registry.php --paths         one mount path per line
 *   php scripts/ci/domain-registry.php --tsv           id<TAB>repo<TAB>path per line
 *
 * A Domain that cannot follow the convention is a decision, not a special
 * case to absorb here: the failure names the id and what the rule derived.
 */
function registryFail(string $message): never
{
    fwrite(STDERR, "domain-registry: {$message}\n");
    exit(2);
}

/**
 * The GitHub owner of a remote URL, in either SSH or HTTPS spelling, including
 * the token-bearing HTTPS form actions/checkout writes. Null when the URL is
 * not a recognisable GitHub remote, so a non-GitHub remote is skipped rather
 * than guessed at.
 */
function remoteOwner(string $url): ?string
{
    $url = trim($url);
    if ($url === '') {
        return null;
    }
    if (preg_match('#^[^@]+@[^:]+:([^/]+)/#', $url, $ssh) === 1) {
        return $ssh[1];
    }
    if (preg_match('#^[a-z+]+://(?:[^@/]+@)?[^/]+/([^/]+)/#', $url.'/', $https) === 1) {
        return $https[1];
    }

    return null;
}

/**
 * Owners to look for Domain repositories under, in order: `origin`, then
 * `upstream`. BLB_DOMAIN_OWNERS overrides both.
 *
 * @return list<string>
 */
function domainOwners(?string $root = null): array
{
    $override = (string) getenv('BLB_DOMAIN_OWNERS');
    if (trim($override) !== '') {
        $owners = array_values(array_filter(array_map('trim', explode(',', $override))));
        if ($owners !== []) {
            return array_values(array_unique($owners));
        }
    }

    $root ??= dirname(__DIR__, 2);
    $owners = [];

    foreach (['origin', 'upstream'] as $remote) {
        $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $process = @proc_open(['git', '-C', $root, 'remote', 'get-url', $remote], $descriptors, $pipes);
        if (! is_resource($process)) {
            continue;
        }
        $url = (string) stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        if (proc_close($process) !== 0) {
            continue;
        }
        $owner = remoteOwner($url);
        if ($owner !== null && ! in_array($owner, $owners, true)) {
            $owners[] = $owner;
        }
    }

    if ($owners === []) {
        registryFail('cannot determine a Domain owner: this checkout has no usable origin or upstream remote. Set BLB_DOMAIN_OWNERS.');
    }

    return $owners;
}

/**
 * `people-connector` -> `PeopleConnector`. The mount directory is the id in
 * StudlyCase; nothing else in the tree spells it differently.
 */
function domainStudly(string $id): string
{
    return implode('', array_map(
        static fn (string $part): string => ucfirst($part),
        explode('-', $id),
    ));
}

/**
 * @return array{
 *     sonar_organization: string,
 *     owners: list<string>,
 *     convention: array{repo_prefix: string, mount_root: string, sonar_separator: string},
 *     domains: array<string, array{repo: string, path: string, sonar_project_key: string, repo_candidates: list<string>}>
 * }
 */
function domainRegistry(?string $path = null, ?string $root = null): array
{
    $path ??= __DIR__.'/domain-repos.json';

    $contents = @file_get_contents($path);
    if ($contents === false) {
        registryFail("cannot read {$path}");
    }

    $data = json_decode($contents, true);
    if (! is_array($data)) {
        registryFail("{$path} is not valid JSON");
    }
    if (($data['schema_version'] ?? null) !== 2) {
        registryFail("{$path} must declare schema_version 2");
    }

    $convention = $data['convention'] ?? null;
    if (! is_array($convention)) {
        registryFail("{$path} has no convention block");
    }
    // An empty repo_prefix or sonar_separator is a real choice; an empty
    // mount_root is not, because every Domain would then derive the same path.
    foreach (['repo_prefix', 'mount_root', 'sonar_separator'] as $key) {
        if (! is_string($convention[$key] ?? null)) {
            registryFail("convention.{$key} is required");
        }
    }
    if ($convention['mount_root'] === '') {
        registryFail('convention.mount_root cannot be empty');
    }

    $ids = $data['domains'] ?? null;
    if (! is_array($ids) || $ids === []) {
        registryFail("{$path} must list at least one domain id");
    }

    $owners = domainOwners($root);
    $domains = [];
    foreach ($ids as $id) {
        if (! is_string($id) || preg_match('/^[a-z][a-z0-9]*(-[a-z0-9]+)*$/', $id) !== 1) {
            registryFail('domain ids are lowercase and hyphen-separated; got '.var_export($id, true));
        }
        if (isset($domains[$id])) {
            registryFail("domain [{$id}] is listed twice");
        }

        $slug = $convention['repo_prefix'].$id;
        $candidates = array_map(static fn (string $owner): string => $owner.'/'.$slug, $owners);
        $domains[$id] = [
            'repo' => $candidates[0],
            'repo_candidates' => $candidates,
            'path' => rtrim($convention['mount_root'], '/').'/'.domainStudly($id),
            'sonar_project_key' => $owners[0].$convention['sonar_separator'].$slug,
        ];
    }

    return [
        'sonar_organization' => (string) ($data['sonar_organization'] ?? ''),
        'owners' => $owners,
        'convention' => [
            'repo_prefix' => (string) $convention['repo_prefix'],
            'mount_root' => (string) $convention['mount_root'],
            'sonar_separator' => (string) $convention['sonar_separator'],
        ],
        'domains' => $domains,
    ];
}

// Included by the other CI scripts; only the direct invocation prints.
if (realpath($argv[0] ?? '') !== realpath(__FILE__)) {
    return;
}

$descriptor = null;
$root = null;
$format = '--json';
foreach (array_slice($argv, 1) as $argument) {
    if (in_array($argument, ['--json', '--paths', '--tsv'], true)) {
        $format = $argument;

        continue;
    }
    if (str_starts_with($argument, '--registry=')) {
        $descriptor = substr($argument, strlen('--registry='));

        continue;
    }
    if (str_starts_with($argument, '--root=')) {
        $root = substr($argument, strlen('--root='));

        continue;
    }
    registryFail("unknown argument {$argument}");
}

$registry = domainRegistry($descriptor, $root);

if ($format === '--paths') {
    foreach ($registry['domains'] as $domain) {
        echo $domain['path']."\n";
    }

    exit(0);
}

if ($format === '--tsv') {
    foreach ($registry['domains'] as $id => $domain) {
        echo $id."\t".$domain['repo']."\t".$domain['path']."\n";
    }

    exit(0);
}

echo json_encode($registry, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n";
