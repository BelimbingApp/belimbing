<?php

namespace App\Base\Foundation\RuntimeRequirements;

use Illuminate\Support\Facades\Process;
use Throwable;

/**
 * Verifies reviewed host-runtime profiles declared by Module manifests.
 *
 * A manifest may select a profile, but it never supplies a command, package,
 * or shell fragment. Package installation remains platform-owned so cloning a
 * private Extension never becomes a privileged code-execution path.
 */
final class RuntimeRequirementVerifier
{
    public const PHP_CLI_SQLSRV = 'php-cli-sqlsrv';

    public const PHP_CLI_MBSTRING = 'php-cli-mbstring';

    /**
     * @param  list<string>  $requirements
     * @return list<string>
     */
    public function unmet(array $requirements): array
    {
        $problems = [];

        foreach (array_values(array_unique($requirements)) as $requirement) {
            match ($requirement) {
                self::PHP_CLI_SQLSRV => $this->phpCliHasSqlsrv()
                    ?: $problems[] = 'The system PHP CLI does not have sqlsrv and pdo_sqlsrv available.',
                self::PHP_CLI_MBSTRING => $this->phpCliHasExtension('mbstring')
                    ?: $problems[] = 'The system PHP CLI does not have mbstring available.',
                default => $problems[] = "Unsupported runtime prerequisite [{$requirement}].",
            };
        }

        return $problems;
    }

    /**
     * @param  list<string>  $requirements
     * @return list<string>
     */
    public function setupCommands(array $requirements): array
    {
        $commands = [];

        if (in_array(self::PHP_CLI_SQLSRV, $requirements, true)) {
            $commands[] = './scripts/setup-steps/16-sqlsrv-cli.sh';
        }

        if (in_array(self::PHP_CLI_MBSTRING, $requirements, true)) {
            $commands[] = './scripts/setup-steps/17-mbstring-cli.sh';
        }

        return $commands;
    }

    private function phpCliHasSqlsrv(): bool
    {
        $binary = $this->phpBinary();

        try {
            $modules = Process::timeout(20)->run([$binary, '-m']);
            $drivers = Process::timeout(20)->run([$binary, '-r', 'echo implode(",", PDO::getAvailableDrivers());']);
        } catch (Throwable) {
            return false;
        }

        if (! $modules->successful() || ! $drivers->successful()) {
            return false;
        }

        $loaded = $this->loadedExtensions($modules->output());
        $pdoDrivers = array_map('strtolower', explode(',', trim($drivers->output())));

        return in_array('sqlsrv', $loaded, true)
            && in_array('pdo_sqlsrv', $loaded, true)
            && in_array('sqlsrv', $pdoDrivers, true);
    }

    private function phpCliHasExtension(string $extension): bool
    {
        try {
            $modules = Process::timeout(20)->run([$this->phpBinary(), '-m']);
        } catch (Throwable) {
            return false;
        }

        return $modules->successful()
            && in_array($extension, $this->loadedExtensions($modules->output()), true);
    }

    private function phpBinary(): string
    {
        return trim((string) getenv('BLB_SYSTEM_PHP_BINARY')) ?: 'php';
    }

    /** @return list<string> */
    private function loadedExtensions(string $output): array
    {
        return array_map('strtolower', preg_split('/\R/', $output) ?: []);
    }
}
