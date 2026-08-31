#!/usr/bin/env php
<?php

declare(strict_types=1);

const DEFAULT_DESCRIPTOR = __DIR__.'/domain-repos.json';
const COMMIT_SHA_PATTERN = '/^[0-9a-f]{40}$/';

function fail(string $message): never
{
    fwrite(STDERR, "domain-ci: {$message}\n");
    exit(1);
}

/** @return array<string, mixed> */
function descriptor(string $path): array
{
    $data = json_decode((string) @file_get_contents($path), true);
    if (! is_array($data) || ($data['schema_version'] ?? null) !== 1 || ! is_array($data['domains'] ?? null)) {
        fail("invalid descriptor {$path}");
    }
    foreach ($data['domains'] as $id => $domain) {
        if (! is_string($id) || preg_match('/^[a-z][a-z0-9-]*$/', $id) !== 1 || ! is_array($domain)) {
            fail('invalid domain ID');
        }
        foreach (['repo', 'path', 'sonar_project_key', 'ref'] as $key) {
            if (! is_string($domain[$key] ?? null) || $domain[$key] === '') {
                fail("{$id}.{$key} is required");
            }
        }
        if (preg_match('#^app/Domains/[A-Z][A-Za-z0-9]*$#', $domain['path']) !== 1) {
            fail("{$id}.path is not a canonical Domain mount path");
        }
        if (preg_match('#^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$#', $domain['repo']) !== 1) {
            fail("{$id}.repo is not an owner/repository slug");
        }
        if (preg_match(COMMIT_SHA_PATTERN, $domain['ref']) !== 1) {
            fail("{$id}.ref must be an immutable 40-character commit SHA");
        }
    }

    return $data;
}

/** @param array<string, mixed> $data */
function render(array $data, string $id, string $workflowRef): string
{
    if (! isset($data['domains'][$id])) {
        fail("unknown domain {$id}");
    }
    if (preg_match(COMMIT_SHA_PATTERN, $workflowRef) !== 1) {
        fail('workflow ref must be an immutable 40-character commit SHA');
    }

    return <<<YAML
name: ci

on:
  push:
    branches: [main]
  pull_request:
    branches: [main]
  workflow_dispatch:

permissions:
  contents: read

jobs:
  ci:
    uses: BelimbingApp/belimbing/.github/workflows/domain-ci.yml@{$workflowRef}
    with:
      domain-id: {$id}
      platform-ref: {$workflowRef}
    secrets:
      SONAR_TOKEN: \${{ secrets.SONAR_TOKEN }}
YAML;
}

/** @param array<string, mixed> $data */
function resolve(array $data, string $id, string $callerRepository, string $workflowRef): string
{
    if (! isset($data['domains'][$id]) || ! is_array($data['domains'][$id])) {
        fail("unknown domain {$id}");
    }
    if (preg_match(COMMIT_SHA_PATTERN, $workflowRef) !== 1) {
        fail('workflow ref must be an immutable 40-character commit SHA');
    }
    $domain = $data['domains'][$id];
    if (strcasecmp((string) $domain['repo'], $callerRepository) !== 0) {
        fail("caller repository {$callerRepository} does not own domain {$id}");
    }

    return 'DOMAIN_PATH='.$domain['path']."\n"
        .'SONAR_PROJECT_KEY='.$domain['sonar_project_key']."\n"
        .'SONAR_ORGANIZATION='.$data['sonar_organization']."\n";
}

$command = $argv[1] ?? '';
$options = [];
foreach (array_slice($argv, 2) as $argument) {
    if (preg_match('/^--([^=]+)=(.*)$/', $argument, $matches) === 1) {
        $options[$matches[1]] = $matches[2];
    }
}
$data = descriptor($options['descriptor'] ?? DEFAULT_DESCRIPTOR);

if ($command === 'validate') {
    fwrite(STDERR, 'domain-ci: descriptor valid ('.count($data['domains'])." domains)\n");
    exit(0);
}
if ($command === 'resolve') {
    echo resolve(
        $data,
        $options['domain-id'] ?? '',
        $options['caller-repository'] ?? '',
        $options['workflow-ref'] ?? '',
    );
    exit(0);
}
if ($command === 'render' || $command === 'verify') {
    $output = render($data, $options['domain-id'] ?? '', $options['workflow-ref'] ?? '');
    if ($command === 'render') {
        echo $output;
        exit(0);
    }
    $caller = $options['caller'] ?? '.github/workflows/ci.yml';
    $actual = @file_get_contents($caller);
    if ($actual === false || rtrim($actual)."\n" !== rtrim($output)."\n") {
        fail("caller drift: {$caller}; regenerate with the render command");
    }
    fwrite(STDERR, "domain-ci: caller verified: {$caller}\n");
    exit(0);
}
fail('expected validate, resolve, render, or verify');
