#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Bootstrap SonarCloud + GitHub Actions secrets for BLB module Distribution Bundles.
 *
 * Reads module registry (scripts/ci/module-repos.json), ensures each module has a
 * SonarCloud project, then publishes SONAR_TOKEN to GitHub:
 *   1. Organization secret (preferred — all current and future BelimbingApp repos)
 *   2. Per-repo fallback when gh lacks admin:org
 *
 * Usage (from platform root):
 *   php scripts/ci/setup-sonar.php
 *   php scripts/ci/setup-sonar.php --verify-only
 *
 * Requires SONAR_TOKEN in the environment or platform .env (see .env.example).
 * Requires gh CLI authenticated with repo (and admin:org for org-level secrets).
 */
const SONAR_HOST = 'https://sonarcloud.io';
const DEFAULT_ORG = 'belimbingapp';
const PROJECT_KEY_PREFIX = 'BelimbingApp_';

final class SonarSetupException extends RuntimeException {}

/**
 * @return array{verify-only: bool, registry: string, github-org: string}
 */
function parseArguments(array $argv): array
{
    $options = [
        'verify-only' => false,
        'registry' => dirname(__DIR__).'/ci/module-repos.json',
        'github-org' => 'BelimbingApp',
    ];

    for ($index = 1; $index < count($argv); $index++) {
        $argument = $argv[$index];

        if ($argument === '--verify-only') {
            $options['verify-only'] = true;

            continue;
        }

        if (str_starts_with($argument, '--registry=')) {
            $options['registry'] = substr($argument, strlen('--registry='));

            continue;
        }

        if (str_starts_with($argument, '--github-org=')) {
            $options['github-org'] = substr($argument, strlen('--github-org='));

            continue;
        }

        fwrite(STDERR, "Unknown argument: {$argument}\n");

        exit(1);
    }

    return $options;
}

function resolveSonarToken(): string
{
    $token = getenv('SONAR_TOKEN');
    if (is_string($token) && $token !== '') {
        return $token;
    }

    $envPath = dirname(__DIR__, 2).'/.env';
    if (! is_readable($envPath)) {
        fwrite(STDERR, "SONAR_TOKEN not set and {$envPath} is not readable.\n");

        exit(1);
    }

    foreach (file($envPath, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
        if (preg_match('/^SONAR_TOKEN=(.*)$/', $line, $matches) === 1) {
            $token = trim($matches[1], " \t\"'");
            if ($token !== '') {
                return $token;
            }
        }
    }

    fwrite(STDERR, "SONAR_TOKEN is missing. Set it in .env or the environment.\n");

    exit(1);
}

/**
 * @return array{
 *     sonar_organization: string,
 *     modules: array<string, array{repo: string, path: string, sonar_project_key?: string}>
 * }
 */
function loadRegistry(string $path): array
{
    if (! is_readable($path)) {
        fwrite(STDERR, "Registry not found: {$path}\n");

        exit(1);
    }

    $registry = json_decode((string) file_get_contents($path), true);
    if (! is_array($registry) || ! isset($registry['modules']) || ! is_array($registry['modules'])) {
        fwrite(STDERR, "Invalid registry: {$path}\n");

        exit(1);
    }

    $registry['sonar_organization'] ??= DEFAULT_ORG;

    return $registry;
}

/**
 * @param  array{repo: string, path: string, sonar_project_key?: string}  $module
 */
function sonarProjectKey(array $module): string
{
    if (isset($module['sonar_project_key']) && $module['sonar_project_key'] !== '') {
        return $module['sonar_project_key'];
    }

    $repo = $module['repo'];
    $shortName = str_contains($repo, '/') ? substr($repo, strrpos($repo, '/') + 1) : $repo;

    return PROJECT_KEY_PREFIX.$shortName;
}

function commandExists(string $command): bool
{
    $output = [];
    $exit = 0;

    if (PHP_OS_FAMILY === 'Windows') {
        exec('where.exe '.escapeshellarg($command).' 2>nul', $output, $exit);
    } else {
        exec('command -v '.escapeshellarg($command).' 2>/dev/null', $output, $exit);
    }

    return $exit === 0;
}

function curlBinary(): string
{
    if (PHP_OS_FAMILY === 'Windows' && commandExists('curl.exe')) {
        return 'curl.exe';
    }

    return 'curl';
}

/**
 * @return array<string, mixed>
 */
function sonarRequest(string $token, string $method, string $path, ?string $body = null): array
{
    if (commandExists('curl') || (PHP_OS_FAMILY === 'Windows' && commandExists('curl.exe'))) {
        return sonarRequestBinary($token, $method, $path, $body);
    }

    return sonarRequestPhpCurl($token, $method, $path, $body);
}

/**
 * @return array<string, mixed>
 */
function sonarRequestBinary(string $token, string $method, string $path, ?string $body = null): array
{
    $url = SONAR_HOST.$path;
    $responseFile = tempnam(sys_get_temp_dir(), 'sonar-response-');
    if ($responseFile === false) {
        throw new SonarSetupException('Unable to create temp file for SonarCloud response.');
    }

    $command = curlBinary()
        .' -sS'
        .' -u '.escapeshellarg($token.':')
        .' -X '.escapeshellarg($method)
        .' -o '.escapeshellarg($responseFile)
        .' -w %{http_code}';

    if ($body !== null) {
        $command .= ' -H '.escapeshellarg('Content-Type: application/x-www-form-urlencoded');
        $command .= ' --data '.escapeshellarg($body);
    }

    $command .= ' '.escapeshellarg($url);

    $statusOutput = [];
    $exit = 0;
    exec($command, $statusOutput, $exit);

    $status = (int) trim($statusOutput[0] ?? '0');
    $response = is_readable($responseFile) ? (string) file_get_contents($responseFile) : '';
    @unlink($responseFile);

    if ($exit !== 0) {
        throw new SonarSetupException('SonarCloud curl request failed.');
    }

    $decoded = json_decode($response, true);
    if (! is_array($decoded)) {
        throw new SonarSetupException("SonarCloud returned non-JSON (HTTP {$status}): {$response}");
    }

    if ($status >= 400) {
        $message = $decoded['errors'][0]['msg'] ?? $decoded['message'] ?? $response;

        throw new SonarSetupException("SonarCloud HTTP {$status}: {$message}");
    }

    return $decoded;
}

/**
 * @return array<string, mixed>
 */
function sonarRequestPhpCurl(string $token, string $method, string $path, ?string $body = null): array
{
    $curl = curl_init(SONAR_HOST.$path);
    if ($curl === false) {
        throw new SonarSetupException('Unable to initialize SonarCloud request.');
    }

    curl_setopt_array($curl, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_USERPWD => $token.':',
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
    ]);

    if ($body !== null) {
        curl_setopt($curl, CURLOPT_POSTFIELDS, $body);
    }

    $response = curl_exec($curl);
    $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
    $curlError = curl_error($curl);
    curl_close($curl);

    if (! is_string($response)) {
        throw new SonarSetupException('SonarCloud request failed: '.$curlError);
    }

    $decoded = json_decode($response, true);
    if (! is_array($decoded)) {
        throw new SonarSetupException("SonarCloud returned non-JSON (HTTP {$status}): {$response}");
    }

    if ($status >= 400) {
        $message = $decoded['errors'][0]['msg'] ?? $decoded['message'] ?? $response;

        throw new SonarSetupException("SonarCloud HTTP {$status}: {$message}");
    }

    return $decoded;
}

function validateSonarToken(string $token): void
{
    $result = sonarRequest($token, 'GET', '/api/authentication/validate');
    if (($result['valid'] ?? false) !== true) {
        throw new SonarSetupException('SONAR_TOKEN is not valid on SonarCloud.');
    }
}

/**
 * @return array<string, true>
 */
function existingSonarProjects(string $token, string $organization): array
{
    $projects = [];
    $page = 1;

    do {
        $result = sonarRequest(
            $token,
            'GET',
            '/api/projects/search?organization='.rawurlencode($organization).'&ps=100&p='.$page,
        );

        foreach ($result['components'] ?? [] as $component) {
            if (is_array($component) && isset($component['key'])) {
                $projects[(string) $component['key']] = true;
            }
        }

        $total = (int) ($result['paging']['total'] ?? 0);
        $pageSize = (int) ($result['paging']['pageSize'] ?? 100);
        $page++;
    } while (($page - 1) * $pageSize < $total);

    return $projects;
}

function ensureSonarProject(string $token, string $organization, string $projectKey, string $name): void
{
    sonarRequest(
        $token,
        'POST',
        '/api/projects/create',
        http_build_query([
            'organization' => $organization,
            'project' => $projectKey,
            'name' => $name,
        ]),
    );
}

function ghAvailable(): bool
{
    if (PHP_OS_FAMILY === 'Windows') {
        exec('where.exe gh 2>nul', $output, $exit);

        return $exit === 0;
    }

    $output = [];
    $exit = 0;
    exec('command -v gh 2>/dev/null', $output, $exit);

    return $exit === 0;
}

function ghSupportsOrgSecrets(): bool
{
    $output = [];
    $exit = 0;
    exec('gh auth status 2>&1', $output, $exit);
    $text = implode("\n", $output);

    return str_contains($text, 'admin:org');
}

/**
 * @return array{ok: bool, mode: string, message: string}
 */
function publishGitHubSecret(string $token, string $githubOrg, array $repos): array
{
    if (! ghAvailable()) {
        return ['ok' => false, 'mode' => 'none', 'message' => 'gh CLI is not available.'];
    }

    if (ghSupportsOrgSecrets()) {
        return publishGitHubOrgSecret($token, $githubOrg);
    }

    return publishGitHubRepoSecrets($token, $repos);
}

/**
 * @return array{ok: bool, mode: string, message: string}
 */
function publishGitHubOrgSecret(string $token, string $githubOrg): array
{
    $command = sprintf(
        'gh secret set SONAR_TOKEN --org %s --visibility all --body %s 2>&1',
        escapeshellarg($githubOrg),
        escapeshellarg($token),
    );
    exec($command, $output, $exit);

    if ($exit === 0) {
        return ['ok' => true, 'mode' => 'org', 'message' => "Published SONAR_TOKEN to org {$githubOrg} (all repositories)."];
    }

    return ['ok' => false, 'mode' => 'org', 'message' => implode("\n", $output)];
}

/**
 * @param  list<string>  $repos
 * @return array{ok: bool, mode: string, message: string}
 */
function publishGitHubRepoSecrets(string $token, array $repos): array
{
    $failures = [];
    foreach ($repos as $repo) {
        $command = sprintf(
            'gh secret set SONAR_TOKEN --repo %s --body %s 2>&1',
            escapeshellarg($repo),
            escapeshellarg($token),
        );
        exec($command, $output, $exit);
        if ($exit !== 0) {
            $failures[] = "{$repo}: ".implode(' ', $output);
        }
    }

    if ($failures === []) {
        return [
            'ok' => true,
            'mode' => 'repo',
            'message' => 'Published SONAR_TOKEN to '.count($repos).' repo(s). Run `gh auth refresh -h github.com -s admin:org` for org-wide coverage.',
        ];
    }

    return ['ok' => false, 'mode' => 'repo', 'message' => implode("\n", $failures)];
}

$options = parseArguments($argv);
$token = resolveSonarToken();
$registry = loadRegistry($options['registry']);
$organization = (string) $registry['sonar_organization'];

validateSonarToken($token);
fwrite(STDERR, "SonarCloud token is valid.\n");

$existing = existingSonarProjects($token, $organization);
$repos = [];
$missing = [];

foreach ($registry['modules'] as $domain => $module) {
    if (! is_array($module) || ! isset($module['repo'])) {
        continue;
    }

    $projectKey = sonarProjectKey($module);
    $repos[] = $module['repo'];
    $shortName = str_contains($module['repo'], '/') ? substr($module['repo'], strrpos($module['repo'], '/') + 1) : $module['repo'];

    if (isset($existing[$projectKey])) {
        fwrite(STDERR, "SonarCloud project exists: {$projectKey} ({$domain})\n");

        continue;
    }

    $missing[] = [$projectKey, $shortName, $domain];
}

if ($options['verify-only']) {
    if ($missing !== []) {
        foreach ($missing as [$projectKey, , $domain]) {
            fwrite(STDERR, "Missing SonarCloud project: {$projectKey} ({$domain})\n");
        }

        exit(1);
    }

    fwrite(STDERR, "SonarCloud registry is complete.\n");
    exit(0);
}

foreach ($missing as [$projectKey, $shortName, $domain]) {
    ensureSonarProject($token, $organization, $projectKey, $shortName);
    fwrite(STDERR, "Created SonarCloud project: {$projectKey} ({$domain})\n");
}

$platformRepo = $options['github-org'].'/belimbing';
if (! in_array($platformRepo, $repos, true)) {
    $repos[] = $platformRepo;
}

$secretResult = publishGitHubSecret($token, $options['github-org'], $repos);
fwrite(STDERR, $secretResult['message']."\n");

if (! $secretResult['ok']) {
    exit(1);
}

fwrite(STDERR, "SonarCloud + GitHub Actions bootstrap complete.\n");
