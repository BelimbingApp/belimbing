#!/usr/bin/env php
<?php

declare(strict_types=1);

final readonly class MutationCase
{
    public function __construct(
        public string $name,
        public string $file,
        public string $search,
        public string $replace,
        public string $command,
    ) {}
}

final readonly class MutationResult
{
    public function __construct(
        public string $name,
        public string $file,
        public string $command,
        public int $exitCode,
        public bool $caught,
        public string $output,
    ) {}
}

final class MutationCheckException extends RuntimeException {}

/**
 * @return list<MutationCase>
 */
function mutationCases(): array
{
    return [
        new MutationCase(
            name: 'final-stream-error-must-terminate',
            file: 'app/Modules/Core/AI/Services/AgenticFinalResponseStreamer.php',
            search: <<<'PHP'
        if ($streamFailed) {
            return;
        }
PHP,
            replace: <<<'PHP'
        if (false) {
            return;
        }
PHP,
            command: './vendor/bin/pest --compact tests/Unit/Modules/Core/AI/Services/AgenticFinalResponseStreamerTest.php',
        ),
        new MutationCase(
            name: 'browser-artifact-test-must-enforce-test-root',
            file: 'app/Modules/Core/AI/Services/Browser/BrowserArtifactStore.php',
            search: <<<'PHP'
        return (string) config('ai.tools.browser.artifact_dir', self::DEFAULT_ARTIFACT_DIR);
PHP,
            replace: <<<'PHP'
        return self::DEFAULT_ARTIFACT_DIR;
PHP,
            command: './vendor/bin/pest --compact tests/Unit/Modules/Core/AI/Services/Browser/BrowserArtifactStoreTest.php',
        ),
    ];
}

function replaceOnce(string $content, string $search, string $replace, string $file): string
{
    $count = substr_count($content, $search);

    if ($count !== 1) {
        throw new MutationCheckException("Expected to find exactly one mutation target in {$file}; found {$count}.");
    }

    return str_replace($search, $replace, $content);
}

function runCommand(string $command): array
{
    $output = [];
    exec($command.' 2>&1', $output, $exitCode);

    return [
        'exit_code' => $exitCode,
        'output' => implode("\n", $output),
    ];
}

/**
 * @return list<MutationResult>
 */
function runMutationChecks(): array
{
    $results = [];

    foreach (mutationCases() as $case) {
        $original = file_get_contents($case->file);

        if ($original === false) {
            throw new MutationCheckException("Could not read {$case->file}.");
        }

        $mutated = replaceOnce($original, $case->search, $case->replace, $case->file);

        file_put_contents($case->file, $mutated);

        try {
            $commandResult = runCommand($case->command);
            $results[] = new MutationResult(
                name: $case->name,
                file: $case->file,
                command: $case->command,
                exitCode: $commandResult['exit_code'],
                caught: $commandResult['exit_code'] !== 0,
                output: $commandResult['output'],
            );
        } finally {
            file_put_contents($case->file, $original);
        }
    }

    return $results;
}

/**
 * @param  list<MutationResult>  $results
 */
function renderMarkdownReport(array $results): string
{
    $lines = [
        '# Critical Mutation Check Report',
        '',
        '| Check | Result | Exit Code | File |',
        '| --- | --- | ---: | --- |',
    ];

    foreach ($results as $result) {
        $lines[] = sprintf(
            '| `%s` | %s | %d | `%s` |',
            $result->name,
            $result->caught ? 'caught' : 'missed',
            $result->exitCode,
            $result->file,
        );
    }

    foreach ($results as $result) {
        $lines[] = '';
        $lines[] = '## '.$result->name;
        $lines[] = '';
        $lines[] = '- File: `'.$result->file.'`';
        $lines[] = '- Command: `'.$result->command.'`';
        $lines[] = '- Result: '.($result->caught ? 'caught' : 'missed');
        $lines[] = '';
        $lines[] = '```text';
        $lines[] = $result->output !== '' ? $result->output : '(no output)';
        $lines[] = '```';
    }

    return implode("\n", $lines)."\n";
}

try {
    $results = runMutationChecks();
    $report = renderMarkdownReport($results);

    fwrite(STDOUT, $report);

    $missedCount = count(array_filter($results, static fn (MutationResult $result): bool => ! $result->caught));
    exit($missedCount > 0 ? 1 : 0);
} catch (Throwable $throwable) {
    fwrite(STDERR, 'Mutation check runner failed: '.$throwable->getMessage().PHP_EOL);
    exit(2);
}
