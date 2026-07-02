<?php

namespace App\Base\Software\Services;

use App\Base\Foundation\Contracts\DomainRuntimeReloader;
use App\Base\Support\PhpCli;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Process;

final class FrankenPhpDomainRuntimeReloader implements DomainRuntimeReloader
{
    public const PENDING_CACHE_KEY = 'domain-runtime.reload.pending';

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
        if (! Cache::add(self::PENDING_CACHE_KEY, now()->utc()->toIso8601String(), now()->addMinutes(2))) {
            return [
                $alreadyScheduledMessage,
            ];
        }

        $result = $this->launchBackgroundReload($clearRuntimeCaches);

        if ($result->successful()) {
            return [
                $scheduledMessage,
            ];
        }

        Cache::forget(self::PENDING_CACHE_KEY);

        $output = trim($result->output()."\n".$result->errorOutput());

        return [
            strtr($failureMessage, [
                ':message' => $output !== '' ? $output : __('process exited with code :code', ['code' => $result->exitCode()]),
            ]),
        ];
    }

    private function launchBackgroundReload(bool $clearRuntimeCaches): ProcessResult
    {
        $command = PhpCli::current()->artisan([
            'blb:domain-runtime:reload',
            '--delay=2',
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
