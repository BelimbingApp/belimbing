<?php

namespace App\Base\Software\Services;

final class PhpExtensionDriftProbe
{
    /**
     * @param  list<string>|null  $iniFiles  Override for testing; defaults to the live php.ini plus any scanned .ini files.
     */
    public function __construct(private readonly ?array $iniFiles = null) {}

    /**
     * Extension names declared as enabled in php.ini but not loaded in this
     * process. FrankenPHP's worker pool loads PHP extensions once, when the
     * OS process starts. Enabling one in php.ini afterward does nothing until
     * the process itself restarts — a worker reload only re-executes the
     * worker script, not PHP's module init, so it cannot pick this up.
     *
     * @return list<string>
     */
    public function missingExtensions(): array
    {
        $declared = $this->declaredExtensions();

        if ($declared === []) {
            return [];
        }

        $loaded = array_map('strtolower', get_loaded_extensions());

        return array_values(array_diff($declared, $loaded));
    }

    /** @return list<string> */
    private function declaredExtensions(): array
    {
        $names = [];

        foreach ($this->iniFiles() as $file) {
            foreach ($this->extensionDirectivesIn($file) as $name) {
                $names[$name] = true;
            }
        }

        return array_keys($names);
    }

    /** @return list<string> */
    private function iniFiles(): array
    {
        $files = $this->iniFiles ?? $this->liveIniFiles();

        return array_values(array_unique(array_filter(
            $files,
            fn (string $file): bool => $file !== '' && is_file($file),
        )));
    }

    /** @return list<string> */
    private function liveIniFiles(): array
    {
        $files = [(string) php_ini_loaded_file()];

        $scanned = php_ini_scanned_files();

        if (is_string($scanned) && trim($scanned) !== '') {
            foreach (explode(',', $scanned) as $file) {
                $files[] = trim($file);
            }
        }

        return $files;
    }

    /** @return list<string> */
    private function extensionDirectivesIn(string $file): array
    {
        $lines = file($file, FILE_IGNORE_NEW_LINES);

        if ($lines === false) {
            return [];
        }

        $names = [];

        foreach ($lines as $line) {
            if (preg_match('/^\s*extension\s*=\s*"?(?:php_)?([A-Za-z0-9_]+)/', $line, $matches) === 1) {
                $names[] = strtolower($matches[1]);
            }
        }

        return $names;
    }
}
