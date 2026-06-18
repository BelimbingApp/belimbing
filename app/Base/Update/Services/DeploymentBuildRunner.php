<?php

namespace App\Base\Update\Services;

use App\Base\Support\PhpCli;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\Process;

/**
 * Runs dependency and asset build commands during a platform update.
 */
class DeploymentBuildRunner
{
    private const FROZEN_LOCKFILE = '--frozen-lockfile';

    public function composerLockHash(): ?string
    {
        $lock = base_path('composer.lock');

        if (! is_file($lock)) {
            return null;
        }

        $hash = md5_file($lock);

        return $hash === false ? null : $hash;
    }

    public function composerInstall(): string
    {
        $args = array_merge($this->composerCommand(), [
            'install',
            '--no-interaction',
            '--prefer-dist',
            '--optimize-autoloader',
        ]);

        if (app()->isProduction()) {
            $args[] = '--no-dev';
        }

        $result = Process::path(base_path())->timeout(900)->run($args);

        if ($result->successful()) {
            return (string) __('PHP dependencies installed.');
        }

        return (string) __('PHP dependency install failed: :error', ['error' => $this->processError($result)]);
    }

    public function composerDumpAutoload(): string
    {
        $result = Process::path(base_path())->timeout(180)->run(
            array_merge($this->composerCommand(), ['dump-autoload', '--optimize', '--no-interaction']),
        );

        if ($result->successful()) {
            return (string) __('Autoloader refreshed.');
        }

        return (string) __('Autoload refresh failed: :error', ['error' => $this->processError($result)]);
    }

    public function frontendPackageManager(): string
    {
        return $this->nodeInstallCommand()[0];
    }

    /**
     * @param  (callable(string): void)|null  $progress
     * @return list<string>
     */
    public function buildAssets(?callable $progress = null): array
    {
        if (! is_file(base_path('package.json'))) {
            $line = (string) __('No package.json found; frontend assets were not rebuilt.');
            if ($progress !== null) {
                $progress($line);
            }

            return [$line];
        }

        $log = [];
        $record = static function (string $line) use (&$log, $progress): void {
            $log[] = $line;
            if ($progress !== null) {
                $progress($line);
            }
        };

        $install = $this->nodeInstallCommand();
        $record((string) __('Installing frontend dependencies (:command)…', ['command' => implode(' ', $install)]));

        $installResult = Process::path(base_path())->timeout(900)->run($install);

        if (! $installResult->successful()) {
            $record((string) __('Frontend dependency install failed: :error', ['error' => $this->processError($installResult)]));

            return $log;
        }

        $record((string) __('Frontend dependencies installed.'));

        $build = $this->nodeBuildCommand();
        $record((string) __('Running frontend build (:command)…', ['command' => implode(' ', $build)]));

        $buildResult = Process::path(base_path())->timeout(600)->run($build);

        if ($buildResult->successful()) {
            $record((string) __('Frontend assets built.'));
        } else {
            $record((string) __('Frontend asset build failed: :error', ['error' => $this->processError($buildResult)]));
        }

        return $log;
    }

    /**
     * @return list<string>
     */
    private function composerCommand(): array
    {
        $phar = base_path('storage/app/.devops/composer.phar');

        return is_file($phar) ? PhpCli::current()->script($phar) : ['composer'];
    }

    /**
     * @return list<string>
     */
    private function nodeInstallCommand(): array
    {
        if (is_file(base_path('bun.lock'))) {
            $args = ['bun', 'install', self::FROZEN_LOCKFILE];

            if (DIRECTORY_SEPARATOR === '\\') {
                $args[] = '--backend';
                $args[] = 'copyfile';
            }

            return $args;
        }

        foreach ([
            'pnpm-lock.yaml' => ['pnpm', 'install', self::FROZEN_LOCKFILE],
            'yarn.lock' => ['yarn', 'install', self::FROZEN_LOCKFILE],
            'package-lock.json' => ['npm', 'ci'],
        ] as $lockfile => $command) {
            if (is_file(base_path($lockfile))) {
                return $command;
            }
        }

        return ['npm', 'install'];
    }

    /**
     * @return list<string>
     */
    private function nodeBuildCommand(): array
    {
        foreach ([
            'bun.lock' => ['bun', 'run', 'build'],
            'pnpm-lock.yaml' => ['pnpm', 'run', 'build'],
            'yarn.lock' => ['yarn', 'run', 'build'],
        ] as $lockfile => $command) {
            if (is_file(base_path($lockfile))) {
                return $command;
            }
        }

        return ['npm', 'run', 'build'];
    }

    private function processError(ProcessResult $result): string
    {
        return trim($result->errorOutput() ?: $result->output())
            ?: (string) __('process exited with code :code', ['code' => $result->exitCode()]);
    }
}
