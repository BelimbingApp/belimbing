<?php

namespace App\Base\Support\Git;

final class GitRepositoryConfigReader
{
    public function __construct(private readonly string $path) {}

    public function remoteUrl(string $remote): ?string
    {
        foreach ($this->gitConfigPaths() as $configPath) {
            $url = $this->remoteUrlFromConfig($configPath, $remote);

            if ($url !== null) {
                return $url;
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private function gitConfigPaths(): array
    {
        $gitDirectory = $this->gitDirectory();

        if ($gitDirectory === null) {
            return [];
        }

        $paths = [$gitDirectory.DIRECTORY_SEPARATOR.'config'];
        $commonDirectory = $this->commonGitDirectory($gitDirectory);

        if ($commonDirectory !== null) {
            $paths[] = $commonDirectory.DIRECTORY_SEPARATOR.'config';
        }

        return array_values(array_unique($paths));
    }

    private function gitDirectory(): ?string
    {
        $dotGit = $this->path.DIRECTORY_SEPARATOR.'.git';

        if (is_dir($dotGit)) {
            return realpath($dotGit) ?: $dotGit;
        }

        if (is_file($dotGit)) {
            return $this->gitDirectoryFromMetadata($dotGit);
        }

        return null;
    }

    private function gitDirectoryFromMetadata(string $dotGit): ?string
    {
        $contents = file_get_contents($dotGit);

        if (! is_string($contents) || preg_match('/^gitdir:\s*(.+)$/i', trim($contents), $matches) !== 1) {
            return null;
        }

        $gitDirectory = trim($matches[1]);

        if (! preg_match('/^(?:[A-Za-z]:[\/\\\\]|\/|\\\\\\\\)/', $gitDirectory)) {
            $gitDirectory = dirname($dotGit).DIRECTORY_SEPARATOR.$gitDirectory;
        }

        return realpath($gitDirectory) ?: $gitDirectory;
    }

    private function commonGitDirectory(string $gitDirectory): ?string
    {
        $commonDirPath = $gitDirectory.DIRECTORY_SEPARATOR.'commondir';

        if (! is_file($commonDirPath)) {
            return null;
        }

        $commonDirectory = trim((string) file_get_contents($commonDirPath));

        if ($commonDirectory === '') {
            return null;
        }

        if (! preg_match('/^(?:[A-Za-z]:[\/\\\\]|\/|\\\\\\\\)/', $commonDirectory)) {
            $commonDirectory = $gitDirectory.DIRECTORY_SEPARATOR.$commonDirectory;
        }

        return realpath($commonDirectory) ?: $commonDirectory;
    }

    private function remoteUrlFromConfig(string $configPath, string $remote): ?string
    {
        $url = null;
        $lines = $this->configLines($configPath);

        if ($lines !== null) {
            $inRemoteSection = false;

            foreach ($lines as $line) {
                if (preg_match('/^\s*\[remote\s+"([^"]+)"\]\s*$/', $line, $matches) === 1) {
                    $inRemoteSection = $matches[1] === $remote;

                    continue;
                }

                if ($inRemoteSection && preg_match('/^\s*url\s*=\s*(.+?)\s*$/', $line, $matches) === 1) {
                    $url = $matches[1];
                    break;
                }
            }
        }

        return $url;
    }

    /**
     * @return list<string>|null
     */
    private function configLines(string $configPath): ?array
    {
        if (! is_file($configPath) || ! is_readable($configPath)) {
            return null;
        }

        $lines = file($configPath, FILE_IGNORE_NEW_LINES);

        return is_array($lines) ? $lines : null;
    }
}
