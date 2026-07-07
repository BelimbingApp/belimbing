<?php

namespace App\Base\AI\Services;

use Symfony\Component\Process\Process;

final class DetachedProcessLauncher
{
    public function __construct(
        private readonly ExecutableLocator $locator,
    ) {}

    /**
     * @param  list<string>  $command
     * @param  array<string, string>  $environment
     */
    public function launch(
        array $command,
        ?string $workingDirectory = null,
        array $environment = [],
        ?string $stdoutPath = null,
        ?string $stderrPath = null,
    ): bool {
        if ($command === []) {
            return false;
        }

        $workingDirectory ??= base_path();

        if (PHP_OS_FAMILY === 'Windows') {
            return $this->launchWindows($command, $workingDirectory, $environment, $stdoutPath, $stderrPath);
        }

        return $this->launchUnix($command, $workingDirectory, $environment, $stdoutPath, $stderrPath);
    }

    /**
     * @param  list<string>  $command
     * @param  array<string, string>  $environment
     */
    private function launchUnix(
        array $command,
        string $workingDirectory,
        array $environment,
        ?string $stdoutPath,
        ?string $stderrPath,
    ): bool {
        $launcher = $this->locator->find('nohup') ?? 'nohup';

        $commandLine = $this->buildUnixCommandLine(
            $launcher,
            $command,
            $workingDirectory,
            $environment,
            $stdoutPath,
            $stderrPath,
        );

        exec($commandLine, result_code: $exitCode);

        return $exitCode === 0;
    }

    /**
     * Build the detached `cd && nohup … &` command line for exec(). Detachment
     * requires a shell string (Symfony Process cannot background), so every
     * interpolated value — the launcher, each command token, env values, the
     * working directory, and redirect paths — is escapeshellarg-quoted here so
     * shell metacharacters in any of them cannot break out of their argument.
     *
     * @param  list<string>  $command
     * @param  array<string, string>  $environment
     */
    public function buildUnixCommandLine(
        string $launcher,
        array $command,
        string $workingDirectory,
        array $environment,
        ?string $stdoutPath,
        ?string $stderrPath,
    ): string {
        $quotedCommand = implode(' ', array_map(escapeshellarg(...), $command));
        $envPrefix = implode(' ', array_map(
            fn (string $key, string $value): string => $key.'='.escapeshellarg($value),
            array_keys($environment),
            array_values($environment),
        ));

        $stdoutRedirect = $stdoutPath !== null
            ? '>> '.escapeshellarg($stdoutPath)
            : '>/dev/null';
        $stderrRedirect = match (true) {
            $stderrPath !== null && $stdoutPath !== null && $stderrPath === $stdoutPath => '2>&1',
            $stderrPath !== null => '2>> '.escapeshellarg($stderrPath),
            default => '2>/dev/null',
        };

        return trim(implode(' ', array_filter([
            'cd '.escapeshellarg($workingDirectory),
            '&&',
            escapeshellarg($launcher),
            $envPrefix,
            $quotedCommand,
            $stdoutRedirect,
            $stderrRedirect,
            '</dev/null',
            '&',
        ])));
    }

    /**
     * @param  list<string>  $command
     * @param  array<string, string>  $environment
     */
    private function launchWindows(
        array $command,
        string $workingDirectory,
        array $environment,
        ?string $stdoutPath,
        ?string $stderrPath,
    ): bool {
        $powershell = $this->locator->find(['pwsh', 'powershell']);
        if ($powershell === null) {
            return false;
        }

        $commandLine = implode(' ', array_map($this->quoteWindowsArgument(...), $command));
        $commandLine .= ' '.$this->windowsRedirects($stdoutPath, $stderrPath);

        $script = implode(' ', array_filter([
            $this->buildWindowsEnvironmentAssignments($environment),
            '$process = Start-Process',
            '-FilePath '.$this->quotePowerShellString('cmd.exe'),
            '-ArgumentList @('
                .$this->quotePowerShellString('/d').', '
                .$this->quotePowerShellString('/s').', '
                .$this->quotePowerShellString('/c').', '
                .$this->quotePowerShellString($commandLine)
            .')',
            '-WorkingDirectory '.$this->quotePowerShellString($workingDirectory),
            '-WindowStyle Hidden',
            '-PassThru;',
            'if ($null -eq $process) { exit 1 }',
        ]));

        $process = new Process([
            $powershell,
            '-NoLogo',
            '-NoProfile',
            '-NonInteractive',
            '-ExecutionPolicy',
            'Bypass',
            '-Command',
            $script,
        ]);
        $process->setTimeout(15);
        $process->run();

        return $process->isSuccessful();
    }

    /**
     * @param  array<string, string>  $environment
     */
    private function buildWindowsEnvironmentAssignments(array $environment): string
    {
        return implode(' ', array_map(
            fn (string $key, string $value): string => '$env:'.$key.' = '.$this->quotePowerShellString($value).';',
            array_keys($environment),
            array_values($environment),
        ));
    }

    private function windowsRedirects(?string $stdoutPath, ?string $stderrPath): string
    {
        $stdout = $stdoutPath !== null
            ? '1>> '.$this->quoteWindowsArgument($stdoutPath)
            : '1>NUL';

        if ($stderrPath !== null && $stdoutPath !== null && $stderrPath === $stdoutPath) {
            return $stdout.' 2>&1';
        }

        $stderr = $stderrPath !== null
            ? '2>> '.$this->quoteWindowsArgument($stderrPath)
            : '2>NUL';

        return $stdout.' '.$stderr;
    }

    private function quotePowerShellString(string $value): string
    {
        return "'".str_replace("'", "''", $value)."'";
    }

    private function quoteWindowsArgument(string $value): string
    {
        if ($value === '') {
            return '""';
        }

        if (! preg_match('/[\s"&|<>^()]/', $value)) {
            return $value;
        }

        return '"'.str_replace('"', '\"', $value).'"';
    }
}
