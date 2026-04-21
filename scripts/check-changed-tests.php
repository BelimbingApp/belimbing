#!/usr/bin/env php
<?php

declare(strict_types=1);

final readonly class ChangedTestFinding
{
    public function __construct(
        public string $severity,
        public string $file,
        public int $line,
        public string $message,
    ) {}
}

/**
 * @return array{base: string|null, head: string|null, files: list<string>}
 */
function parseArguments(array $argv): array
{
    $base = null;
    $head = null;
    $files = [];

    for ($index = 1; $index < count($argv); $index++) {
        $argument = $argv[$index];

        if (str_starts_with($argument, '--base=')) {
            $base = substr($argument, strlen('--base='));

            continue;
        }

        if (str_starts_with($argument, '--head=')) {
            $head = substr($argument, strlen('--head='));

            continue;
        }

        if ($argument === '--files') {
            $files = array_slice($argv, $index + 1);

            break;
        }
    }

    return [
        'base' => $base !== '' ? $base : null,
        'head' => $head !== '' ? $head : null,
        'files' => array_values(array_filter($files, static fn (string $file): bool => $file !== '')),
    ];
}

/**
 * @return list<string>
 */
function changedFiles(string $base, string $head): array
{
    $command = sprintf(
        'git diff --name-only --diff-filter=ACMR %s %s -- tests',
        escapeshellarg($base),
        escapeshellarg($head),
    );

    exec($command, $output, $exitCode);

    if ($exitCode !== 0) {
        fwrite(STDERR, "Failed to compute changed test files via git diff.\n");
        exit(2);
    }

    return array_values(array_filter($output, static fn (string $line): bool => $line !== ''));
}

/**
 * @return list<string>
 */
function phpTestFiles(array $files): array
{
    return array_values(array_filter($files, static function (string $file): bool {
        return str_starts_with($file, 'tests/')
            && str_ends_with($file, '.php')
            && is_file($file);
    }));
}

/**
 * @return list<ChangedTestFinding>
 */
function inspectFile(string $file): array
{
    $content = file_get_contents($file);

    if ($content === false) {
        return [
            new ChangedTestFinding('error', $file, 1, 'Could not read changed test file.'),
        ];
    }

    $rules = [
        [
            'severity' => 'error',
            'message' => 'Do not point ai.workspace_path into storage/app/. Use storage/framework/testing/... instead.',
            'pattern' => '/config\(\)->set\(\s*[\'"]ai\.workspace_path[\'"]\s*,\s*storage_path\(\s*[\'"]app\//',
        ],
        [
            'severity' => 'error',
            'message' => 'Do not point ai.workspace_path into storage/app/. Use storage/framework/testing/... instead.',
            'pattern' => '/config\(\s*\[\s*[\'"]ai\.workspace_path[\'"]\s*=>\s*storage_path\(\s*[\'"]app\//',
        ],
        [
            'severity' => 'error',
            'message' => 'Tests must not touch the real AI workspace under storage/app/ai/workspace/.',
            'pattern' => '/storage_path\(\s*[\'"]app\/ai\/workspace\//',
        ],
        [
            'severity' => 'error',
            'message' => 'Tests must not touch the real AI wire-log directory under storage/app/ai/wire-logs/.',
            'pattern' => '/storage_path\(\s*[\'"]app\/ai\/wire-logs\//',
        ],
        [
            'severity' => 'error',
            'message' => 'Tests must not touch the default browser artifact directory under storage/app/browser-artifacts/.',
            'pattern' => '/storage_path\(\s*[\'"]app\/browser-artifacts\//',
        ],
        [
            'severity' => 'error',
            'message' => 'Tests must not use a real runtime workspace path under storage/app/ai/workspace/.',
            'pattern' => '/[\'"]storage\/app\/ai\/workspace\//',
        ],
        [
            'severity' => 'error',
            'message' => 'Tests must not use a real runtime wire-log path under storage/app/ai/wire-logs/.',
            'pattern' => '/[\'"]storage\/app\/ai\/wire-logs\//',
        ],
        [
            'severity' => 'error',
            'message' => 'Tests must not use the default browser artifact path under storage/app/browser-artifacts/.',
            'pattern' => '/[\'"]storage\/app\/browser-artifacts\//',
        ],
        [
            'severity' => 'warning',
            'message' => 'Literal storage/app/ paths in tests are suspicious. Prefer storage/framework/testing/... or a test-specific redirected path.',
            'pattern' => '/[\'"]storage\/app\/(?!testing\/)/',
        ],
    ];

    $findings = [];

    foreach ($rules as $rule) {
        preg_match_all($rule['pattern'], $content, $matches, PREG_OFFSET_CAPTURE);

        foreach ($matches[0] as [$match, $offset]) {
            $line = substr_count(substr($content, 0, $offset), "\n") + 1;
            $findings[] = new ChangedTestFinding($rule['severity'], $file, $line, $rule['message']);
        }
    }

    return $findings;
}

function emitFinding(ChangedTestFinding $finding): void
{
    $annotationType = $finding->severity === 'error' ? 'error' : 'warning';
    $annotation = sprintf(
        '::%s file=%s,line=%d::%s',
        $annotationType,
        $finding->file,
        $finding->line,
        str_replace("\n", ' ', $finding->message),
    );

    echo $annotation.PHP_EOL;
    echo strtoupper($finding->severity).": {$finding->file}:{$finding->line} {$finding->message}".PHP_EOL;
}

$arguments = parseArguments($argv);
$files = $arguments['files'];

if ($files === []) {
    if ($arguments['base'] === null || $arguments['head'] === null) {
        fwrite(STDERR, "Usage: php scripts/check-changed-tests.php --base=<sha> --head=<sha>\n");
        fwrite(STDERR, "   or: php scripts/check-changed-tests.php --files <file> [<file> ...]\n");
        exit(2);
    }

    $files = changedFiles($arguments['base'], $arguments['head']);
}

$testFiles = phpTestFiles($files);

if ($testFiles === []) {
    echo 'No changed PHP tests detected.'.PHP_EOL;

    exit(0);
}

$findings = [];

foreach ($testFiles as $file) {
    array_push($findings, ...inspectFile($file));
}

if ($findings === []) {
    echo 'Changed PHP test guardrails passed for '.count($testFiles).' file(s).'.PHP_EOL;

    exit(0);
}

$errorCount = 0;
$warningCount = 0;

foreach ($findings as $finding) {
    emitFinding($finding);

    if ($finding->severity === 'error') {
        $errorCount++;
    } else {
        $warningCount++;
    }
}

echo sprintf(
    'Changed test guardrails finished with %d error(s) and %d warning(s).',
    $errorCount,
    $warningCount,
).PHP_EOL;

exit($errorCount > 0 ? 1 : 0);
