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
 * A pin bought repeatability: the same revisions every run, and with them a
 * combination that had been tested together. What it did not buy is collision
 * safety — RouteCollisionException, TableRegistry and
 * IncubatingSchemaConflictException live in the application and fire at boot
 * wherever it starts, pinned or not. #940 records the trade: a standing
 * maintenance chore, paid whether or not anything is wrong, against a known
 * combination and the ability to replay one. docs/ci/domain-ci.md sets out
 * both sides.
 *
 *   php scripts/ci/domain-registry.php --json          resolved registry as JSON
 *   php scripts/ci/domain-registry.php --paths         one mount path per line
 *   php scripts/ci/domain-registry.php --tsv           id<TAB>repo<TAB>path per line
 *   php scripts/ci/domain-registry.php --materialize   clone every Domain, or
 *       --materialize=<id,id>                          the named ones, into place
 *
 * --materialize is the only supported way to put a Domain on disk, because it
 * is the only one that walks `repo_candidates`. A caller that reads --tsv and
 * runs its own `git clone` gets the first candidate and no fallback, which
 * silently turns a partial fork into a hard failure. It prints one
 * id<TAB>repo<TAB>path<TAB>sha record per materialized Domain to stdout.
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
 * The GitHub.com owner of a remote URL, in either SSH or HTTPS spelling,
 * including the token-bearing HTTPS form actions/checkout writes.
 *
 * The host must be github.com exactly. A near-miss like github.internal.example
 * is a different service: taking its path segment as an owner and then cloning
 * `https://github.com/<owner>/…` would fetch an unrelated public repository and
 * compose it as though the checkout had vouched for it (#944 review). An
 * enterprise or self-hosted remote is skipped, never translated; set
 * BLB_DOMAIN_OWNERS to say who owns the Domains in that case.
 */
function remoteOwner(string $url): ?string
{
    $url = trim($url);
    if ($url === '') {
        return null;
    }
    if (preg_match('#^[^@/]+@([^:/]+):([^/]+)/#', $url, $ssh) === 1) {
        return isGitHubHost($ssh[1]) ? $ssh[2] : null;
    }
    if (preg_match('#^[a-z+]+://(?:[^@/]+@)?([^/]+)/([^/]+)/#', $url.'/', $https) === 1) {
        return isGitHubHost($https[1]) ? $https[2] : null;
    }

    return null;
}

/** github.com itself, with an optional port, and nothing that merely resembles it. */
function isGitHubHost(string $host): bool
{
    $host = strtolower((string) preg_replace('/:\d+$/', '', $host));

    return $host === 'github.com' || $host === 'www.github.com';
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

/**
 * Run a command, returning [exit code, stdout, stderr].
 *
 * @return array{0: int, 1: string, 2: string}
 */
function registryRun(array $command): array
{
    $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (! is_resource($process)) {
        return [1, '', 'cannot start '.implode(' ', $command)];
    }
    $stdout = (string) stream_get_contents($pipes[1]);
    $stderr = (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    return [proc_close($process), $stdout, $stderr];
}

/**
 * Put one Domain on disk, trying every candidate owner in remote order. An
 * existing mount is accepted as it stands: composition never rewrites a
 * checkout someone else placed.
 *
 * @param  array{repo: string, path: string, repo_candidates: list<string>}  $domain
 * @return array{ok: bool, repo: string|null, sha: string|null, errors: list<string>}
 */
function materializeDomain(array $domain, string $root): array
{
    $path = $root.'/'.trim($domain['path'], '/');
    $head = static function (string $path): ?string {
        [$code, $out] = registryRun(['git', '-C', $path, 'rev-parse', 'HEAD']);

        return $code === 0 && trim($out) !== '' ? trim($out) : null;
    };

    if (is_dir($path)) {
        return ['ok' => true, 'repo' => null, 'sha' => $head($path), 'errors' => []];
    }

    $errors = [];
    foreach ($domain['repo_candidates'] as $repo) {
        [$code, , $error] = registryRun(['git', 'clone', '--quiet', '--depth', '1', "https://github.com/{$repo}.git", $path]);
        if ($code === 0) {
            return ['ok' => true, 'repo' => $repo, 'sha' => $head($path), 'errors' => $errors];
        }
        // A candidate that does not host this Domain is expected on a fork;
        // keep the message so a total failure can name every attempt.
        $errors[] = $repo.': '.(trim($error) !== '' ? trim($error) : "git clone exited {$code}");
        if (is_dir($path)) {
            registryRun(['rm', '-rf', $path]);
        }
    }

    return ['ok' => false, 'repo' => null, 'sha' => null, 'errors' => $errors];
}

// Included by the other CI scripts; only the direct invocation prints.
if (realpath($argv[0] ?? '') !== realpath(__FILE__)) {
    return;
}

$descriptor = null;
$root = null;
$only = null;
$format = '--json';
foreach (array_slice($argv, 1) as $argument) {
    if (in_array($argument, ['--json', '--paths', '--tsv', '--materialize'], true)) {
        $format = $argument;

        continue;
    }
    if (str_starts_with($argument, '--materialize=')) {
        $format = '--materialize';
        $only = array_values(array_filter(array_map('trim', explode(',', substr($argument, strlen('--materialize='))))));

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

if ($format === '--materialize') {
    $target = rtrim((string) ($root ?? dirname(__DIR__, 2)), '/');
    $ids = $only ?? array_keys($registry['domains']);
    $failed = [];

    foreach ($ids as $id) {
        $domain = $registry['domains'][$id] ?? null;
        if ($domain === null) {
            registryFail("descriptor does not list domain [{$id}]");
        }

        $result = materializeDomain($domain, $target);
        if (! $result['ok']) {
            $failed[] = "{$id}:\n  ".implode("\n  ", $result['errors']);

            continue;
        }

        $repo = $result['repo'] ?? $domain['repo'];
        fwrite(STDERR, sprintf(
            "domain-registry: %s %s -> %s at %s\n",
            $result['repo'] === null ? 'already mounted' : 'cloned',
            $repo,
            $domain['path'],
            substr((string) $result['sha'], 0, 8) ?: 'unknown',
        ));
        echo $id."\t".$repo."\t".$domain['path']."\t".((string) $result['sha'])."\n";
    }

    if ($failed !== []) {
        // Exit 1, not the usage code: every candidate was tried and none
        // answered, which is a real failure rather than a caller mistake.
        fwrite(STDERR, "domain-registry: could not materialize:\n".implode("\n", $failed)."\n");
        exit(1);
    }

    exit(0);
}

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
