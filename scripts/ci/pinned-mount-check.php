#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Refuse a domain lane that leans on a sibling Domain API absent at the pinned
 * revision CI will compose (#927).
 *
 * `domain-ci` resolves siblings from scripts/ci/domain-repos.json, which pins
 * every Domain at an immutable SHA, while an author's local mount is that
 * Domain's clone at `main`. So a connector test calling a People method that
 * landed after the pin is green locally and red on all three CI jobs, and the
 * local run that would disprove the author's own bug is the one they already
 * did. This check closes that gap before `ready.sh`, not after a reviewer has
 * spent time on it.
 *
 * What it compares: for every changed PHP file, the sibling Domain classes it
 * names, and the method names it calls that exist on those classes at the
 * MOUNTED revision but not at the PINNED one. That pair — the name is used
 * here, and the sibling grew it after the pin — is the signal. A method name
 * that is absent from the sibling class at both revisions belongs to some
 * other object and is not this check's business.
 *
 * What it cannot see: runtime shape. #927's other symptom — a test asserting
 * an absolute count of People tables (40 on main, 33 at the pin) — is a
 * migration-count difference no source scan detects. Compose the pin and run
 * the suite for that class of drift.
 */
const DEFAULT_REGISTRY = __DIR__.'/domain-repos.json';

function fail(string $message): never
{
    fwrite(STDERR, "pinned-mount-check: {$message}\n");
    exit(2);
}

/**
 * @return array{root: string, registry: string, base: string, files: list<string>}
 */
function parseArguments(array $argv): array
{
    $root = getcwd() !== false ? getcwd() : '.';
    $registry = DEFAULT_REGISTRY;
    $base = '';
    $files = [];

    for ($index = 1; $index < count($argv); $index++) {
        $argument = $argv[$index];

        if (str_starts_with($argument, '--root=')) {
            $root = rtrim(substr($argument, strlen('--root=')), '/');

            continue;
        }

        if (str_starts_with($argument, '--registry=')) {
            $registry = substr($argument, strlen('--registry='));

            continue;
        }

        if (str_starts_with($argument, '--base=')) {
            $base = substr($argument, strlen('--base='));

            continue;
        }

        if (str_starts_with($argument, '--')) {
            fail("unknown argument {$argument}");
        }

        $files[] = $argument;
    }

    if ($base === '' && $files === []) {
        fail('give --base=<ref> or one or more changed PHP files');
    }

    return ['root' => $root, 'registry' => $registry, 'base' => $base, 'files' => $files];
}

/**
 * @return array<string, array{repo: string, path: string, ref: string}>
 */
function domains(string $registry): array
{
    $data = json_decode((string) @file_get_contents($registry), true);

    if (! is_array($data) || ! is_array($data['domains'] ?? null)) {
        fail("invalid registry {$registry}");
    }

    $domains = [];

    foreach ($data['domains'] as $id => $domain) {
        if (! is_string($id) || ! is_array($domain)) {
            fail('invalid registry entry');
        }

        foreach (['repo', 'path', 'ref'] as $key) {
            if (! is_string($domain[$key] ?? null) || $domain[$key] === '') {
                fail("{$id}.{$key} is required");
            }
        }

        $domains[$id] = ['repo' => $domain['repo'], 'path' => $domain['path'], 'ref' => $domain['ref']];
    }

    return $domains;
}

/** @return array{0: int, 1: string} exit status and combined output */
function run(string $directory, string ...$command): array
{
    $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $process = proc_open($command, $descriptors, $pipes, $directory);

    if (! is_resource($process)) {
        fail('cannot start git');
    }

    $stdout = (string) stream_get_contents($pipes[1]);
    $stderr = (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    return [proc_close($process), $stdout !== '' ? $stdout : $stderr];
}

/**
 * Files changed against the base, relative to the platform root.
 *
 * @return list<string>
 */
function changedFiles(string $root, string $base): array
{
    [$status, $output] = run($root, 'git', 'diff', '--name-only', '--diff-filter=d', $base.'...HEAD');

    if ($status !== 0) {
        fail("cannot diff against {$base}: ".trim($output));
    }

    return array_values(array_filter(
        array_map('trim', explode("\n", $output)),
        static fn (string $file): bool => $file !== '' && str_ends_with($file, '.php'),
    ));
}

/** The revision a mount is actually on, or null when it is not a git checkout. */
function mountedRevision(string $root, string $path): ?string
{
    if (! is_dir($root.'/'.$path.'/.git')) {
        return null;
    }

    [$status, $output] = run($root.'/'.$path, 'git', 'rev-parse', 'HEAD');

    return $status === 0 ? trim($output) : null;
}

/** A file's contents at a revision inside a mount, or null when it is absent there. */
function fileAtRevision(string $mount, string $revision, string $relative): ?string
{
    [$status, $output] = run($mount, 'git', 'show', $revision.':'.$relative);

    return $status === 0 ? $output : null;
}

/**
 * Method names a class body declares.
 *
 * @return list<string>
 */
function declaredMethods(string $source): array
{
    preg_match_all('/function\s+([A-Za-z_][A-Za-z0-9_]*)\s*\(/', $source, $matches);

    return array_values(array_unique($matches[1]));
}

/**
 * Method names a file calls, whether statically or on an instance.
 *
 * @return list<string>
 */
function calledMethods(string $source): array
{
    preg_match_all('/(?:->|::)([A-Za-z_][A-Za-z0-9_]*)\s*\(/', $source, $matches);

    return array_values(array_unique($matches[1]));
}

/**
 * Sibling Domain classes a file names, as namespace => list of short names.
 *
 * Both `use App\Domains\People\...\Store;` and a fully qualified reference in
 * the body count: a test that only ever says `\App\Domains\People\X::y()` is
 * exactly as exposed as one with a use statement.
 *
 * @return list<string> fully qualified class names
 */
function referencedDomainClasses(string $source): array
{
    $classes = [];

    preg_match_all('/^\s*use\s+(App\\\\Domains\\\\[A-Za-z0-9_\\\\]+);/m', $source, $useMatches);
    foreach ($useMatches[1] as $class) {
        $classes[] = $class;
    }

    preg_match_all('/\\\\?(App\\\\Domains\\\\[A-Za-z0-9_\\\\]+)::/', $source, $fqMatches);
    foreach ($fqMatches[1] as $class) {
        $classes[] = $class;
    }

    return array_values(array_unique($classes));
}

/**
 * The domain a class belongs to, by matching its namespace against a mount path.
 *
 * `app/Domains/PeopleConnector` owns `App\Domains\PeopleConnector\...`, so the
 * longest matching mount wins — `People` must not claim `PeopleConnector`.
 *
 * @param  array<string, array{repo: string, path: string, ref: string}>  $domains
 * @return array{0: string, 1: array{repo: string, path: string, ref: string}}|null
 */
function owningDomain(array $domains, string $class): ?array
{
    $best = null;
    $bestLength = 0;

    foreach ($domains as $id => $domain) {
        $namespace = 'App\\'.str_replace('/', '\\', substr($domain['path'], strlen('app/'))).'\\';

        if (str_starts_with($class, $namespace) && strlen($namespace) > $bestLength) {
            $best = [$id, $domain];
            $bestLength = strlen($namespace);
        }
    }

    return $best;
}

/**
 * The domain whose mount a changed file lives in, or null for platform code.
 *
 * The domain under review is composed at the PR head, not at its pin, so its
 * references to its own classes are never the drift this check is about. Only
 * SIBLING domains are pinned. Without this, every connector lane reports its
 * own newly added classes as missing.
 *
 * @param  array<string, array{repo: string, path: string, ref: string}>  $domains
 */
function owningDomainOfFile(array $domains, string $file): ?string
{
    $best = null;
    $bestLength = 0;

    foreach ($domains as $id => $domain) {
        $prefix = $domain['path'].'/';

        if (str_starts_with($file, $prefix) && strlen($prefix) > $bestLength) {
            $best = $id;
            $bestLength = strlen($prefix);
        }
    }

    return $best;
}

/** The path of a class file inside its own mount, by PSR-4 convention. */
function classFileInMount(string $class, string $mountPath): string
{
    $namespace = 'App\\'.str_replace('/', '\\', substr($mountPath, strlen('app/'))).'\\';

    return str_replace('\\', '/', substr($class, strlen($namespace))).'.php';
}

$arguments = parseArguments($argv);
$root = $arguments['root'];
$domains = domains($arguments['registry']);

$files = $arguments['files'] !== []
    ? $arguments['files']
    : changedFiles($root, $arguments['base']);

// One resolution per (class, revision) pair: a lane's tests name the same few
// sibling classes over and over, and each miss costs a git show.
$methodCache = [];
$findings = [];
$skipped = [];

foreach ($files as $file) {
    $absolute = str_starts_with($file, '/') ? $file : $root.'/'.$file;
    $source = @file_get_contents($absolute);

    if ($source === false) {
        continue;
    }

    $called = calledMethods($source);

    if ($called === []) {
        continue;
    }

    $fileDomain = owningDomainOfFile($domains, $file);

    foreach (referencedDomainClasses($source) as $class) {
        $owner = owningDomain($domains, $class);

        if ($owner === null) {
            continue;
        }

        [$id, $domain] = $owner;

        if ($id === $fileDomain) {
            continue;
        }
        $mount = $root.'/'.$domain['path'];
        $mounted = mountedRevision($root, $domain['path']);

        if ($mounted === null) {
            $skipped[$id] = "{$domain['path']} is not a git checkout";

            continue;
        }

        if ($mounted === $domain['ref']) {
            continue;
        }

        $relative = classFileInMount($class, $domain['path']);
        $key = $class.'@'.$domain['ref'];

        if (! array_key_exists($key, $methodCache)) {
            $pinnedSource = fileAtRevision($mount, $domain['ref'], $relative);
            $mountedSource = fileAtRevision($mount, $mounted, $relative);

            $methodCache[$key] = [
                'pinned' => $pinnedSource === null ? null : declaredMethods($pinnedSource),
                'mounted' => $mountedSource === null ? [] : declaredMethods($mountedSource),
            ];
        }

        $pinnedMethods = $methodCache[$key]['pinned'];
        $mountedMethods = $methodCache[$key]['mounted'];

        if ($pinnedMethods === null) {
            $findings[] = [
                'file' => $file,
                'domain' => $id,
                'class' => $class,
                'member' => null,
                'pin' => $domain['ref'],
                'mounted' => $mounted,
            ];

            continue;
        }

        // Only a name the sibling grew after the pin is evidence. A call the
        // pinned class never had and still does not have belongs elsewhere.
        foreach ($called as $method) {
            if (in_array($method, $mountedMethods, true) && ! in_array($method, $pinnedMethods, true)) {
                $findings[] = [
                    'file' => $file,
                    'domain' => $id,
                    'class' => $class,
                    'member' => $method,
                    'pin' => $domain['ref'],
                    'mounted' => $mounted,
                ];
            }
        }
    }
}

foreach ($skipped as $id => $reason) {
    fwrite(STDERR, "pinned-mount-check: skipped {$id} — {$reason}\n");
}

if ($findings === []) {
    fwrite(STDERR, "pinned-mount-check: ok\n");
    exit(0);
}

fwrite(STDERR, "pinned-mount-check: this lane uses sibling Domain API that CI does not compose.\n");

foreach ($findings as $finding) {
    $what = $finding['member'] === null
        ? "{$finding['class']} does not exist"
        : "{$finding['class']}::{$finding['member']}() does not exist";

    fwrite(STDERR, sprintf(
        "  %s: %s at the pinned %s revision %s (your mount is on %s)\n",
        $finding['file'],
        $what,
        $finding['domain'],
        substr($finding['pin'], 0, 12),
        substr($finding['mounted'], 0, 12),
    ));
}

fwrite(STDERR, "Advance the pin in scripts/ci/domain-repos.json first, or keep the lane on API the pin has.\n");

exit(1);
