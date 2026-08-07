<?php

namespace App\Base\Software\Services;

use App\Base\Foundation\Contracts\DomainRuntimeReloader;
use App\Base\Support\PhpCli;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;

final class FrankenPhpDomainRuntimeReloader implements DomainRuntimeReloader
{
    public const PENDING_CACHE_KEY = 'domain-runtime.reload.pending';

    /**
     * Run id handed to the reload that this instance last launched, or null when
     * nothing was launched (already scheduled, or the process failed to start).
     * The caller stamps it on the run record it writes for the same click, which
     * is what lets the detached process close that record when it finishes.
     */
    private ?string $scheduledRunId = null;

    public function __construct(private readonly DeploymentRunHistory $history) {}

    public function scheduledRunId(): ?string
    {
        return $this->scheduledRunId;
    }

    /**
     * @return list<string>
     */
    public function reloadAfterDomainChange(): array
    {
        return $this->scheduleBackgroundReload(
            clearRuntimeCaches: false,
            scheduledMessage: (string) __('Domain runtime reload scheduled in the background.'),
            alreadyScheduledMessage: (string) __('Domain runtime reload is already scheduled.'),
            failureMessage: (string) __('Warning: domain runtime reload could not be scheduled: :message'),
        );
    }

    /**
     * @return list<string>
     */
    public function reloadAfterSoftwareUpdate(): array
    {
        return $this->scheduleBackgroundReload(
            clearRuntimeCaches: true,
            scheduledMessage: (string) __('Runtime reload scheduled in the background.'),
            alreadyScheduledMessage: (string) __('Runtime reload is already scheduled.'),
            failureMessage: (string) __('Warning: runtime reload could not be scheduled: :message'),
        );
    }

    private function scheduleBackgroundReload(
        bool $clearRuntimeCaches,
        string $scheduledMessage,
        string $alreadyScheduledMessage,
        string $failureMessage,
    ): array {
        $this->scheduledRunId = null;

        if (! Cache::add(self::PENDING_CACHE_KEY, now()->utc()->toIso8601String(), now()->addMinutes(2))) {
            return [
                $alreadyScheduledMessage,
            ];
        }

        $runId = (string) Str::uuid();
        $result = $this->launchBackgroundReload($clearRuntimeCaches, $runId);

        if ($result->successful()) {
            $this->scheduledRunId = $runId;
            $this->history->rememberReloadScheduled($scheduledMessage);

            return [
                $scheduledMessage,
            ];
        }

        Cache::forget(self::PENDING_CACHE_KEY);

        $output = trim($result->output()."\n".$result->errorOutput());
        $message = strtr($failureMessage, [
            ':message' => $output !== '' ? $output : __('process exited with code :code', ['code' => $result->exitCode()]),
        ]);

        $this->history->rememberReload(false, $message, '');

        return [$message];
    }

    private function launchBackgroundReload(bool $clearRuntimeCaches, string $runId): ProcessResult
    {
        $command = PhpCli::current()->artisan([
            'blb:domain-runtime:reload',
            '--delay=2',
            '--run-id='.$runId,
        ]);

        if ($clearRuntimeCaches) {
            $command[] = '--clear-runtime-caches';
        }

        $out = storage_path('logs/domain-runtime-reload.out.log');
        $err = storage_path('logs/domain-runtime-reload.err.log');

        if (! is_dir(dirname($out))) {
            mkdir(dirname($out), 0775, true);
        }

        if (PHP_OS_FAMILY === 'Windows') {
            return Process::path(base_path())
                ->timeout(10)
                ->run([
                    'powershell',
                    '-NoProfile',
                    '-NonInteractive',
                    '-ExecutionPolicy',
                    'Bypass',
                    '-Command',
                    $this->windowsStartProcessCommand($command, $out, $err),
                ]);
        }

        return Process::path(base_path())
            ->timeout(10)
            ->run([
                'sh',
                '-c',
                'nohup '.$this->shellCommand($command).' > '.escapeshellarg($out).' 2> '.escapeshellarg($err).' &',
            ]);
    }

    /**
     * @param  list<string>  $command
     */
    private function windowsStartProcessCommand(array $command, string $out, string $err): string
    {
        $executable = array_shift($command) ?: 'php';

        return implode('; ', [
            '$ErrorActionPreference = \'Stop\'',
            'Start-Process -FilePath '.$this->powershellQuote($executable)
                .' -ArgumentList '.$this->powershellArray($command)
                .' -WorkingDirectory '.$this->powershellQuote(base_path())
                .' -WindowStyle Hidden'
                .' -RedirectStandardOutput '.$this->powershellQuote($out)
                .' -RedirectStandardError '.$this->powershellQuote($err),
        ]);
    }

    /**
     * @param  list<string>  $values
     */
    private function powershellArray(array $values): string
    {
        return '@('.implode(', ', array_map($this->powershellQuote(...), $values)).')';
    }

    private function powershellQuote(string $value): string
    {
        return '\''.str_replace('\'', '\'\'', $value).'\'';
    }

    /**
     * @param  list<string>  $command
     */
    private function shellCommand(array $command): string
    {
        return implode(' ', array_map('escapeshellarg', $command));
    }
}
