<?php

namespace App\Base\Database\Services;

final class IncubatingMigrationFiles
{
    /**
     * @param  list<string>  $migrationPaths
     * @return list<string>
     */
    public function paths(array $migrationPaths): array
    {
        $files = [];

        foreach ($migrationPaths as $path) {
            if (is_file($path)) {
                $files[] = $path;

                continue;
            }

            if (is_dir($path)) {
                $files = array_merge($files, glob(rtrim($path, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.'*.php') ?: []);
            }
        }

        $files = array_values(array_unique($files));
        sort($files);

        return $files;
    }

    public function pathByFileName(string $migrationFile): ?string
    {
        $paths = [];

        foreach ($this->defaultDiscoveryPathPatterns() as $pattern) {
            $paths = array_merge($paths, glob($pattern) ?: []);
        }

        foreach ($this->paths($paths) as $path) {
            if (basename($path) === $migrationFile) {
                return $path;
            }
        }

        return null;
    }

    public function fileIsIncubating(string $migrationFile): bool
    {
        $path = $this->pathByFileName($migrationFile);

        if ($path === null) {
            return false;
        }

        $contents = file_get_contents($path);

        return $contents !== false && $this->contentsAreIncubating($contents);
    }

    public function contentsAreIncubating(string $contents): bool
    {
        return preg_match('/\buse\s+IncubatingSchema\s*;/i', $contents) === 1;
    }

    /**
     * @return list<string>
     */
    private function defaultDiscoveryPathPatterns(): array
    {
        return [
            app_path('Base/*/Database/Migrations'),
            app_path('Modules/*/*/Database/Migrations'),
            database_path('migrations'),
            base_path('extensions/*/*/Database/Migrations'),
        ];
    }
}
