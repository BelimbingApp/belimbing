<?php

namespace App\Base\AI\Services;

use App\Base\AI\Exceptions\ShellBackendUnavailableException;
use App\Base\Support\ExecutableLocator;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\Process;

final class ShellCommandRunner
{
    public function __construct(
        private readonly ExecutableLocator $locator,
    ) {}

    public function backendName(): string
    {
        return $this->resolveBackend()['name'];
    }

    public function backendLabel(): string
    {
        return $this->resolveBackend()['label'];
    }

    /**
     * @return list<string>
     */
    public function command(string $command): array
    {
        $backend = $this->resolveBackend();

        return match ($backend['name']) {
            'powershell' => [
                $backend['binary'],
                '-NoLogo',
                '-NoProfile',
                '-NonInteractive',
                '-ExecutionPolicy',
                'Bypass',
                '-Command',
                $command,
            ],
            'bash' => [
                $backend['binary'],
                '-lc',
                $command,
            ],
            default => throw new ShellBackendUnavailableException('Unsupported shell backend: '.$backend['name']),
        };
    }

    public function run(string $command, string $workingDirectory, int $timeoutSeconds): ProcessResult
    {
        return Process::timeout($timeoutSeconds)
            ->path($workingDirectory)
            ->run($this->command($command));
    }

    /**
     * @return array{0: resource, 1: array<int, resource>}|null
     */
    public function openStreamingProcess(string $command, string $workingDirectory): ?array
    {
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $pipes = null;
        $process = proc_open(
            $this->command($command),
            $descriptors,
            $pipes,
            $workingDirectory,
        );

        if (! is_resource($process) || ! is_array($pipes)) {
            return null;
        }

        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        return [$process, $pipes];
    }

    /**
     * @return array{name: string, label: string, binary: string}
     */
    private function resolveBackend(): array
    {
        $configured = strtolower((string) config('ai.shell.backend', 'auto'));

        return match ($configured) {
            'auto' => $this->resolveAutoBackend(),
            'bash' => $this->resolveBashBackend(),
            'powershell', 'pwsh' => $this->resolvePowerShellBackend(),
            default => throw new ShellBackendUnavailableException(
                'Unsupported AI shell backend ['.$configured.']. Configure ai.shell.backend as auto, bash, or powershell.'
            ),
        };
    }

    /**
     * @return array{name: string, label: string, binary: string}
     */
    private function resolveAutoBackend(): array
    {
        $preferred = PHP_OS_FAMILY === 'Windows'
            ? [$this->tryResolvePowerShellBackend(...), $this->tryResolveBashBackend(...)]
            : [$this->tryResolveBashBackend(...), $this->tryResolvePowerShellBackend(...)];

        foreach ($preferred as $resolver) {
            $resolved = $resolver();
            if ($resolved !== null) {
                return $resolved;
            }
        }

        throw new ShellBackendUnavailableException(
            'No supported shell backend is available. Install PowerShell or Bash, or configure ai.shell.backend to a supported backend.'
        );
    }

    /**
     * @return array{name: string, label: string, binary: string}
     */
    private function resolveBashBackend(): array
    {
        return $this->tryResolveBashBackend()
            ?? throw new ShellBackendUnavailableException(
                'Bash is not available. Install Bash or choose a different ai.shell.backend.'
            );
    }

    /**
     * @return array{name: string, label: string, binary: string}
     */
    private function resolvePowerShellBackend(): array
    {
        return $this->tryResolvePowerShellBackend()
            ?? throw new ShellBackendUnavailableException(
                'PowerShell is not available. Install PowerShell or choose a different ai.shell.backend.'
            );
    }

    /**
     * @return array{name: string, label: string, binary: string}|null
     */
    private function tryResolveBashBackend(): ?array
    {
        $configured = config('ai.shell.bash_binary');
        $candidates = is_string($configured) && $configured !== '' ? [$configured] : ['bash'];
        $binary = $this->locator->find($candidates);

        if ($binary === null) {
            return null;
        }

        return [
            'name' => 'bash',
            'label' => 'Bash',
            'binary' => $binary,
        ];
    }

    /**
     * @return array{name: string, label: string, binary: string}|null
     */
    private function tryResolvePowerShellBackend(): ?array
    {
        $configured = config('ai.shell.powershell_binary');
        $candidates = is_string($configured) && $configured !== ''
            ? [$configured]
            : ['pwsh', 'powershell'];
        $binary = $this->locator->find($candidates);

        if ($binary === null) {
            return null;
        }

        return [
            'name' => 'powershell',
            'label' => 'PowerShell',
            'binary' => $binary,
        ];
    }
}
