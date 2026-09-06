#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Keep only clover file entries under the domain mount path.
 *
 * Domain CI composes sibling domains beside the suite under test. Pest may
 * still record coverage for those dependency trees (e.g. People Skills while
 * scanning the connector). Sonar must attribute coverage only to the domain
 * whose sonar_project_key owns the scan.
 */

function usage(): never
{
    fwrite(STDERR, "usage: filter-domain-coverage-clover.php --domain-path=<path> --coverage=<clover.xml>\n");
    exit(2);
}

/** @return array{domain-path: string, coverage: string} */
function parseArguments(array $argv): array
{
    $domainPath = null;
    $coverage = null;

    foreach (array_slice($argv, 1) as $argument) {
        if (str_starts_with($argument, '--domain-path=')) {
            $domainPath = substr($argument, strlen('--domain-path='));
            continue;
        }
        if (str_starts_with($argument, '--coverage=')) {
            $coverage = substr($argument, strlen('--coverage='));
        }
    }

    if ($domainPath === null || $domainPath === '' || $coverage === null || $coverage === '') {
        usage();
    }

    return ['domain-path' => rtrim($domainPath, '/'), 'coverage' => $coverage];
}

$options = parseArguments($argv);
$coveragePath = $options['coverage'];
$domainPath = $options['domain-path'];

if (! is_readable($coveragePath)) {
    fwrite(STDERR, "filter-domain-coverage-clover: coverage file not readable: {$coveragePath}\n");
    exit(1);
}

$xml = new DOMDocument;
$xml->preserveWhiteSpace = false;
$xml->formatOutput = true;
if (! @$xml->load($coveragePath)) {
    fwrite(STDERR, "filter-domain-coverage-clover: invalid clover XML: {$coveragePath}\n");
    exit(1);
}

$prefix = $domainPath.'/';
$removed = 0;
$kept = 0;

$files = $xml->getElementsByTagName('file');
// Collect nodes first — live NodeList mutates while removing.
$nodes = [];
foreach ($files as $file) {
    $nodes[] = $file;
}

foreach ($nodes as $file) {
    $name = $file->getAttribute('name');
    $normalized = str_replace('\\', '/', $name);
    // Clover may use absolute paths; match on the domain mount suffix.
    $inDomain = str_starts_with($normalized, $prefix)
        || str_contains($normalized, '/'.$prefix)
        || $normalized === $domainPath;

    if (! $inDomain) {
        $file->parentNode?->removeChild($file);
        $removed++;
        continue;
    }
    $kept++;
}

if (! $xml->save($coveragePath)) {
    fwrite(STDERR, "filter-domain-coverage-clover: failed to write {$coveragePath}\n");
    exit(1);
}

fwrite(STDERR, "filter-domain-coverage-clover: kept {$kept} file(s), removed {$removed} outside {$domainPath}\n");
