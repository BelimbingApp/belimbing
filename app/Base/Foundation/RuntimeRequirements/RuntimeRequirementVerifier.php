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
 *
 * Checks run against the shared host CLI by default, which is what install-time
 * preflight means. A caller that starts its own subprocess may name the
 * executable it will actually run: a preflight against a different binary than
 * the process under test is not a preflight, since it can pass while that
 * process fails (SB-Tape/blb-sbg#16, where an Extension resolved
 * SBG_AX_PHP_BINARY while this class only ever saw BLB_SYSTEM_PHP_BINARY).
 */
final class RuntimeRequirementVerifier
{
    public const PHP_CLI_SQLSRV = 'php-cli-sqlsrv';

    public const PHP_CLI_MBSTRING = 'php-cli-mbstring';

    /**
     * @param  list<string>  $requirements
     * @param  string|null  $binary  the executable to probe; null means the shared host CLI
     * @return list<string>
     */
    public function unmet(array $requirements, ?string $binary = null): array
    {
        $problems = [];

        foreach (array_values(array_unique($requirements)) as $requirement) {
            match ($requirement) {
                self::PHP_CLI_SQLSRV => $this->phpCliHasSqlsrv($binary)
                    ?: $problems[] = $this->describe($binary).' does not have sqlsrv and pdo_sqlsrv available.',
                self::PHP_CLI_MBSTRING => $this->phpCliHasExtension('mbstring', $binary)
                    ?: $problems[] = $this->describe($binary).' does not have mbstring available.',
                default => $problems[] = "Unsupported runtime prerequisite [{$requirement}].",
            };
        }

        return $problems;
    }

    /**
     * Name the executable only when the caller chose one.
     *
     * An installer preflighting the shared host CLI has no better name for it
     * than "the system PHP CLI", and an operator reading that message will
     * look at the right thing. Once a caller supplies its own binary that
     * wording becomes actively misleading -- it sends the operator to fix a
     * CLI that was never checked.
     */
    private function describe(?string $binary): string
    {
        $explicit = trim((string) $binary);

        return $explicit === ''
            ? 'The system PHP CLI'
            : sprintf('The PHP CLI at [%s]', $explicit);
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

    private function phpCliHasSqlsrv(?string $requested = null): bool
    {
        $binary = $this->phpBinary($requested);

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

    private function phpCliHasExtension(string $extension, ?string $requested = null): bool
    {
        try {
            $modules = Process::timeout(20)->run([$this->phpBinary($requested), '-m']);
        } catch (Throwable) {
            return false;
        }

        return $modules->successful()
            && in_array($extension, $this->loadedExtensions($modules->output()), true);
    }

    private function phpBinary(?string $requested = null): string
    {
        $explicit = trim((string) $requested);

        return $explicit !== ''
            ? $explicit
            : (trim((string) getenv('BLB_SYSTEM_PHP_BINARY')) ?: 'php');
    }

    /** @return list<string> */
    private function loadedExtensions(string $output): array
    {
        return array_map('strtolower', preg_split('/\R/', $output) ?: []);
    }
}
