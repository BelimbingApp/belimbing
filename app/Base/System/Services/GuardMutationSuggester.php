<?php

namespace App\Base\System\Services;

use App\Base\System\Exceptions\GuardMutationException;

/** Finds removable guard lines in production classes referenced by one test file. */
final readonly class GuardMutationSuggester
{
    public function __construct(private ?string $rootPath = null) {}

    /**
     * @return list<array{file: string, pattern: string, test: string}>
     */
    public function suggest(string $testPath): array
    {
        $test = $this->resolveFile($testPath, 'test');
        $testContents = $this->read($test);
        $displayTest = $this->displayPath($test);
        $suggestions = [];

        foreach ($this->referencedProductionFiles($testContents) as $source) {
            foreach ($this->guardLineNumbers($this->read($source)) as $lineNumber) {
                $suggestions[] = [
                    'file' => $this->displayPath($source),
                    'pattern' => (string) $lineNumber,
                    'test' => $displayTest,
                ];
            }
        }

        return $suggestions;
    }

    /** @return list<string> */
    private function referencedProductionFiles(string $testContents): array
    {
        preg_match_all(
            '/^\s*use\s+(App\\\\[A-Za-z_][A-Za-z0-9_\\\\]*)(?:\s+as\s+[A-Za-z_][A-Za-z0-9_]*)?\s*;/m',
            $testContents,
            $imports,
        );
        preg_match_all(
            '/(?<![A-Za-z0-9_\\\\])(App\\\\(?:[A-Za-z_][A-Za-z0-9_]*\\\\)*[A-Za-z_][A-Za-z0-9_]*)/',
            $testContents,
            $qualifiedNames,
        );

        $files = [];
        foreach (array_unique([...$imports[1], ...$qualifiedNames[1]]) as $class) {
            $relative = 'app/'.str_replace('\\', '/', substr($class, 4)).'.php';
            $candidate = $this->root().'/'.$relative;
            $real = realpath($candidate);

            if ($real !== false && is_file($real)) {
                $files[] = $real;
            }
        }

        sort($files);

        return array_values(array_unique($files));
    }

    /** @return list<int> */
    private function guardLineNumbers(string $source): array
    {
        $lines = preg_split('/\R/', $source) ?: [];
        $guards = [];

        foreach ($lines as $index => $line) {
            $directGuard = preg_match('/\babort_unless\s*\(/', $line) === 1
                || preg_match('/\bthrow\s+new\b/', $line) === 1
                || preg_match('/->where\s*\(\s*([\'\"])(?:[A-Za-z0-9_]+\.)?(?:tenant_id|company_id)\1/', $line) === 1;
            $conditionalExit = preg_match('/\bif\s*\(.*\)\s*\{?\s*(?:return|throw)\b/', $line) === 1
                || ($this->isReturnLine($line) && $this->previousLineOpensIf($lines, $index));

            if ($directGuard || $conditionalExit) {
                $guards[] = $index + 1;
            }
        }

        return $guards;
    }

    private function isReturnLine(string $line): bool
    {
        return preg_match('/^\s*return\b.+;\s*$/', $line) === 1;
    }

    /** @param list<string> $lines */
    private function previousLineOpensIf(array $lines, int $index): bool
    {
        for ($cursor = $index - 1; $cursor >= 0; $cursor--) {
            $previous = trim($lines[$cursor]);
            if ($previous === '') {
                continue;
            }

            if ($previous === '{' && $cursor > 0) {
                $previous = trim($lines[$cursor - 1]);
            }

            return preg_match('/^if\s*\(/', $previous) === 1;
        }

        return false;
    }

    private function resolveFile(string $path, string $kind): string
    {
        $candidate = str_starts_with($path, DIRECTORY_SEPARATOR)
            ? $path
            : $this->root().'/'.ltrim($path, '/');
        $real = realpath($candidate);

        if ($real === false || ! is_file($real) || ! str_starts_with($real, $this->root().'/')) {
            throw new GuardMutationException(ucfirst($kind)." file [{$path}] does not exist inside the application root.");
        }

        return $real;
    }

    private function read(string $path): string
    {
        $contents = file_get_contents($path);
        if ($contents === false) {
            throw new GuardMutationException("Unable to read file [{$path}].");
        }

        return $contents;
    }

    private function displayPath(string $path): string
    {
        return ltrim(substr($path, strlen($this->root())), '/');
    }

    private function root(): string
    {
        $root = realpath($this->rootPath ?? base_path());

        if ($root === false) {
            throw new GuardMutationException('Application root does not exist.');
        }

        return rtrim($root, '/');
    }
}
