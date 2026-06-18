<?php

namespace App\Base\Support;

/**
 * Resolves the PHP CLI command from inside web workers and shell commands.
 *
 * FrankenPHP may be the current runtime binary, but artisan and Composer need
 * PHP CLI. On Windows setup.ps1 installs php.exe beside frankenphp.exe; on
 * Linux the fallback is FrankenPHP's php-cli subcommand when no wrapper exists.
 */
final class PhpCli
{
    public function __construct(
        private readonly ?string $environmentPhpBinary = null,
        private readonly ?string $phpBinary = null,
        private readonly ?string $phpBindir = null,
        private readonly int $majorVersion = PHP_MAJOR_VERSION,
        private readonly int $minorVersion = PHP_MINOR_VERSION,
    ) {}

    public static function current(): self
    {
        return new self(
            environmentPhpBinary: getenv('PHP_BINARY') ?: null,
            phpBinary: PHP_BINARY,
            phpBindir: PHP_BINDIR,
        );
    }

    /**
     * @param  list<string>  $arguments
     * @return list<string>
     */
    public function artisan(array $arguments = []): array
    {
        return $this->script('artisan', $arguments);
    }

    /**
     * @param  list<string>  $arguments
     * @return list<string>
     */
    public function script(string $script, array $arguments = []): array
    {
        return [...$this->commandPrefix(), $script, ...$arguments];
    }

    /**
     * @return list<string>
     */
    public function commandPrefix(): array
    {
        foreach ($this->candidateBinaries() as $candidate) {
            $prefix = $this->commandPrefixFor($candidate);

            if ($prefix !== null) {
                return $prefix;
            }
        }

        return ['php'];
    }

    /**
     * @return list<string>
     */
    private function candidateBinaries(): array
    {
        $candidates = [
            $this->environmentPhpBinary,
            $this->phpBinary,
            ...$this->phpBindirCandidates(),
            '/usr/local/bin/php',
        ];

        $unique = [];
        foreach ($candidates as $candidate) {
            if (! is_string($candidate) || trim($candidate) === '' || in_array($candidate, $unique, true)) {
                continue;
            }

            $unique[] = $candidate;
        }

        return $unique;
    }

    /**
     * @return list<string>
     */
    private function phpBindirCandidates(): array
    {
        if (! is_string($this->phpBindir) || trim($this->phpBindir) === '') {
            return [];
        }

        $directory = rtrim($this->phpBindir, '/\\');

        return array_map(
            fn (string $name): string => $directory.DIRECTORY_SEPARATOR.$name,
            $this->phpExecutableNames(versionedFirst: true),
        );
    }

    /**
     * @return list<string>|null
     */
    private function commandPrefixFor(string $candidate): ?array
    {
        $candidate = trim($candidate);

        if ($this->isFrankenPhp($candidate)) {
            if (! $this->isCommandName($candidate) && ! $this->isUsableFile($candidate)) {
                return null;
            }

            foreach ($this->frankenPhpSidecars($candidate) as $sidecar) {
                if ($this->isUsableFile($sidecar)) {
                    return [$sidecar];
                }
            }

            return [$candidate, 'php-cli'];
        }

        if ($this->isCommandName($candidate)) {
            return [$candidate];
        }

        return $this->isUsableFile($candidate) ? [$candidate] : null;
    }

    /**
     * @return list<string>
     */
    private function frankenPhpSidecars(string $frankenPhp): array
    {
        if ($this->isCommandName($frankenPhp)) {
            return [];
        }

        $directory = dirname($frankenPhp);

        return array_map(
            fn (string $name): string => $directory.DIRECTORY_SEPARATOR.$name,
            $this->phpExecutableNames(versionedFirst: false),
        );
    }

    /**
     * @return list<string>
     */
    private function phpExecutableNames(bool $versionedFirst): array
    {
        $versioned = 'php'.$this->majorVersion.'.'.$this->minorVersion;
        $plain = 'php';

        $names = $versionedFirst
            ? [$versioned.'.exe', $versioned, $plain.'.exe', $plain]
            : [$plain.'.exe', $plain, $versioned.'.exe', $versioned];

        return array_values(array_unique($names));
    }

    private function isFrankenPhp(string $candidate): bool
    {
        return str_contains(strtolower($this->baseName($candidate)), 'frankenphp');
    }

    private function isCommandName(string $candidate): bool
    {
        return ! str_contains($candidate, '/') && ! str_contains($candidate, '\\');
    }

    private function isUsableFile(string $path): bool
    {
        return is_file($path)
            && (is_executable($path) || str_ends_with(strtolower($path), '.exe'));
    }

    private function baseName(string $path): string
    {
        return basename(str_replace('\\', '/', $path));
    }
}
