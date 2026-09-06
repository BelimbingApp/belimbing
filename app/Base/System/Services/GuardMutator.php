<?php

namespace App\Base\System\Services;

use App\Base\System\Exceptions\GuardMutationException;
use Closure;

/**
 * Temporarily deletes exactly one matching source line, runs a test file, then restores.
 *
 * Authors paste the Markdown summary into PR bodies as mutation-control evidence.
 */
final class GuardMutator
{
    /**
     * @param  Closure(string $testPath): array{exit_code: int, output?: string, passed?: int|null, failed?: int|null, assertions?: int|null}  $testRunner
     */
    public function __construct(
        private readonly Closure $testRunner,
    ) {}

    /**
     * @return array{
     *     file: string,
     *     matcher: string,
     *     removed_line: string,
     *     removed_line_number: int,
     *     before: array{passed: int|null, failed: int|null, assertions: int|null, exit_code: int},
     *     after: array{passed: int|null, failed: int|null, assertions: int|null, exit_code: int},
     *     restored: bool,
     *     markdown: string
     * }
     */
    public function run(string $sourcePath, string $lineOrPattern, string $testPath): array
    {
        $absoluteSource = $this->assertReadableFile($sourcePath, 'source');
        $absoluteTest = $this->assertReadableFile($testPath, 'test');

        $original = $this->readFile($absoluteSource);
        [$lineNumber, $removedLine, $mutated] = $this->removeExactlyOneMatch($original, $lineOrPattern);

        $before = $this->invokeRunner($absoluteTest);
        $after = $this->mutateRunRestore($absoluteSource, $original, $mutated, $absoluteTest);

        $result = [
            'file' => $absoluteSource,
            'matcher' => $lineOrPattern,
            'removed_line' => rtrim($removedLine, "\r\n"),
            'removed_line_number' => $lineNumber,
            'before' => $before,
            'after' => $after,
            'restored' => true,
            'markdown' => '',
        ];
        $result['markdown'] = $this->formatMarkdown($result);

        return $result;
    }

    /**
     * Refuse before any mutation when a matcher is missing or ambiguous.
     */
    public function assertUniqueMatch(string $sourcePath, string $lineOrPattern): void
    {
        $absoluteSource = $this->assertReadableFile($sourcePath, 'source');
        $original = $this->readFile($absoluteSource);
        $this->removeExactlyOneMatch($original, $lineOrPattern);
    }

    /**
     * Validate every entry, then mutate/restore each in sequence.
     *
     * @param  list<array<string, mixed>>  $entries
     * @return array{
     *     results: list<array{
     *         file: string,
     *         matcher: string,
     *         removed_line: string,
     *         removed_line_number: int,
     *         before: array{passed: int|null, failed: int|null, assertions: int|null, exit_code: int},
     *         after: array{passed: int|null, failed: int|null, assertions: int|null, exit_code: int},
     *         restored: bool,
     *         markdown: string
     *     }>,
     *     markdown: string
     * }
     */
    public function runBatch(array $entries): array
    {
        if ($entries === []) {
            throw new GuardMutationException('Batch requires at least one {file, pattern, test} entry.');
        }

        $normalized = [];
        foreach ($entries as $index => $entry) {
            $normalized[] = $this->normalizeBatchEntry($entry, $index + 1);
        }

        foreach ($normalized as $entry) {
            $this->assertReadableFile($entry['test'], 'test');
            $this->assertUniqueMatch($entry['file'], $entry['pattern']);
        }

        $results = [];
        foreach ($normalized as $entry) {
            $results[] = $this->run($entry['file'], $entry['pattern'], $entry['test']);
        }

        return [
            'results' => $results,
            'markdown' => $this->formatBatchMarkdown($results),
        ];
    }

    /**
     * @return array{file: string, pattern: string, test: string}
     */
    private function normalizeBatchEntry(mixed $entry, int $position): array
    {
        $label = 'batch entry '.$position;
        if (! is_array($entry)) {
            throw new GuardMutationException("{$label} must be an object with file, pattern, and test.");
        }

        $normalized = [];
        foreach (['file', 'pattern', 'test'] as $key) {
            $value = $entry[$key] ?? null;
            if (! is_string($value) || trim($value) === '') {
                throw new GuardMutationException("{$label} is missing a non-empty \"{$key}\" string.");
            }
            $normalized[$key] = $value;
        }

        return $normalized;
    }

    /**
     * @param  list<array{
     *     file: string,
     *     matcher: string,
     *     removed_line: string,
     *     removed_line_number: int,
     *     before: array{passed: int|null, failed: int|null, assertions: int|null, exit_code: int},
     *     after: array{passed: int|null, failed: int|null, assertions: int|null, exit_code: int},
     *     restored: bool
     * }>  $results
     */
    private function formatBatchMarkdown(array $results): string
    {
        $lines = [
            '**Mutation evidence (batch)**',
            '',
            '| Mutation | Before | After | Restored |',
            '| --- | --- | --- | --- |',
        ];

        foreach ($results as $result) {
            $mutation = '`'.$this->displayPath($result['file']).'` / `'.$result['matcher'].'` (line '.$result['removed_line_number'].')';
            $lines[] = '| '.$mutation
                .' | '.$this->formatCounts($result['before'])
                .' | '.$this->formatCounts($result['after'])
                .' | '.($result['restored'] ? 'yes' : 'no').' |';
        }

        return implode("\n", $lines)."\n";
    }

    /**
     * @return array{passed: int|null, failed: int|null, assertions: int|null, exit_code: int, output: string}
     */
    private function mutateRunRestore(string $absoluteSource, string $original, string $mutated, string $absoluteTest): array
    {
        $this->writeFile($absoluteSource, $mutated);

        try {
            return $this->invokeRunner($absoluteTest);
        } finally {
            $this->writeFile($absoluteSource, $original);
            if (file_get_contents($absoluteSource) !== $original) {
                throw new GuardMutationException("Failed to restore source file [{$absoluteSource}] after mutation.");
            }
        }
    }

    /**
     * @return array{passed: int|null, failed: int|null, assertions: int|null, exit_code: int, output: string}
     */
    private function invokeRunner(string $testPath): array
    {
        /** @var array{exit_code: int, output?: string, passed?: int|null, failed?: int|null, assertions?: int|null} $raw */
        $raw = ($this->testRunner)($testPath);
        $parsed = $this->parsePestSummary((string) ($raw['output'] ?? ''));

        return [
            'passed' => array_key_exists('passed', $raw) ? $raw['passed'] : $parsed['passed'],
            'failed' => array_key_exists('failed', $raw) ? $raw['failed'] : $parsed['failed'],
            'assertions' => array_key_exists('assertions', $raw) ? $raw['assertions'] : $parsed['assertions'],
            'exit_code' => (int) $raw['exit_code'],
            'output' => (string) ($raw['output'] ?? ''),
        ];
    }

    /**
     * @return array{0: int, 1: string, 2: string}
     */
    private function removeExactlyOneMatch(string $contents, string $lineOrPattern): array
    {
        [$lines, $endsWithNewline] = $this->splitLines($contents);
        $matches = $this->matchingLineNumbers($lines, $lineOrPattern);

        if ($matches === []) {
            throw new GuardMutationException("Pattern [{$lineOrPattern}] matched zero lines.");
        }

        if (count($matches) > 1) {
            throw new GuardMutationException(
                'Pattern ['.$lineOrPattern.'] matched '.count($matches).' lines ('.implode(', ', $matches).'); expected exactly one.',
            );
        }

        $lineNumber = $matches[0];
        $removedLine = $lines[$lineNumber - 1];
        array_splice($lines, $lineNumber - 1, 1);
        $mutated = implode("\n", $lines);
        if ($endsWithNewline) {
            $mutated .= "\n";
        }

        return [$lineNumber, $removedLine, $mutated];
    }

    /**
     * @param  list<string>  $lines
     * @return list<int>
     */
    private function matchingLineNumbers(array $lines, string $lineOrPattern): array
    {
        if (ctype_digit($lineOrPattern)) {
            $target = (int) $lineOrPattern;
            if ($target < 1 || $target > count($lines)) {
                throw new GuardMutationException("Line [{$lineOrPattern}] is out of range for the source file (".count($lines).' lines).');
            }

            return [$target];
        }

        $matches = [];
        foreach ($lines as $index => $line) {
            if (str_contains($line, $lineOrPattern)) {
                $matches[] = $index + 1;
            }
        }

        return $matches;
    }

    /**
     * @return array{0: list<string>, 1: bool}
     */
    private function splitLines(string $contents): array
    {
        $lines = preg_split("/\r\n|\n|\r/", $contents) ?: [];
        $endsWithNewline = str_ends_with($contents, "\n") || str_ends_with($contents, "\r");

        if ($endsWithNewline && $lines !== [] && end($lines) === '') {
            array_pop($lines);
        }

        return [$lines, $endsWithNewline];
    }

    /**
     * @param  array{
     *     file: string,
     *     matcher: string,
     *     removed_line: string,
     *     removed_line_number: int,
     *     before: array{passed: int|null, failed: int|null, assertions: int|null, exit_code: int},
     *     after: array{passed: int|null, failed: int|null, assertions: int|null, exit_code: int},
     *     restored: bool
     * }  $result
     */
    private function formatMarkdown(array $result): string
    {
        $relativeFile = $this->displayPath($result['file']);

        return implode("\n", [
            '**Mutation evidence**',
            '',
            '- File: `'.$relativeFile.'`',
            '- Matcher: `'.$result['matcher'].'`',
            '- Removed line '.$result['removed_line_number'].': `'.$this->oneLine($result['removed_line']).'`',
            '- Before: '.$this->formatCounts($result['before']),
            '- After: '.$this->formatCounts($result['after']),
            '- Restored: '.($result['restored'] ? 'yes' : 'no'),
        ])."\n";
    }

    /**
     * @param  array{passed: int|null, failed: int|null, assertions: int|null, exit_code: int}  $counts
     */
    private function formatCounts(array $counts): string
    {
        $parts = [];
        if ($counts['failed'] !== null && $counts['failed'] > 0) {
            $parts[] = $counts['failed'].' failed';
        }
        if ($counts['passed'] !== null) {
            $parts[] = $counts['passed'].' passed';
        }
        $summary = $parts === [] ? 'exit '.$counts['exit_code'] : implode(', ', $parts);
        if ($counts['assertions'] !== null) {
            $summary .= ' / '.$counts['assertions'].' assertions';
        }

        return $summary;
    }

    /**
     * @return array{passed: int|null, failed: int|null, assertions: int|null}
     */
    public function parsePestSummary(string $output): array
    {
        $passed = null;
        $failed = null;
        $assertions = null;

        if (preg_match('/Tests:\s*([^\n]+)/', $output, $testsMatch) === 1) {
            $segment = $testsMatch[1];
            if (preg_match('/(\d+)\s+passed/', $segment, $m) === 1) {
                $passed = (int) $m[1];
            }
            if (preg_match('/(\d+)\s+failed/', $segment, $m) === 1) {
                $failed = (int) $m[1];
            }
            if ($failed === null && preg_match('/\bfailed\b/i', $segment) !== 1) {
                $failed = 0;
            }
        }

        if (preg_match('/\((\d+)\s+assertions?\)/', $output, $assertMatch) === 1) {
            $assertions = (int) $assertMatch[1];
        }

        return [
            'passed' => $passed,
            'failed' => $failed,
            'assertions' => $assertions,
        ];
    }

    private function assertReadableFile(string $path, string $label): string
    {
        $absolute = $this->absolutePath($path);
        if (! is_file($absolute) || ! is_readable($absolute)) {
            throw new GuardMutationException("The {$label} file [{$path}] does not exist or is not readable.");
        }

        return $absolute;
    }

    private function readFile(string $absolute): string
    {
        $contents = file_get_contents($absolute);
        if ($contents === false) {
            throw new GuardMutationException("Unable to read source file [{$absolute}].");
        }

        return $contents;
    }

    private function writeFile(string $absolute, string $contents): void
    {
        if (file_put_contents($absolute, $contents) === false) {
            throw new GuardMutationException("Unable to write source file [{$absolute}].");
        }
    }

    private function absolutePath(string $path): string
    {
        if ($path !== '' && ($path[0] === '/' || preg_match('/^[A-Za-z]:[\\\\\\/]/', $path) === 1)) {
            return $path;
        }

        return base_path($path);
    }

    private function displayPath(string $absolute): string
    {
        $base = base_path();
        if (str_starts_with($absolute, $base.DIRECTORY_SEPARATOR) || str_starts_with($absolute, $base.'/')) {
            return ltrim(substr($absolute, strlen($base)), DIRECTORY_SEPARATOR.'/');
        }

        return $absolute;
    }

    private function oneLine(string $value): string
    {
        return str_replace(["\r", "\n", '`'], ['', '', "'"], $value);
    }
}
