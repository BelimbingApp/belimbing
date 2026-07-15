<?php

namespace App\Base\Software\Services;

use App\Base\Support\DetachedProcessLauncher;
use App\Base\Support\PhpCli;
use Illuminate\Cache\Lock;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

final class SoftwareUpdateLauncher
{
    public const LOCK_KEY = 'software.deployment.update';

    private const LOCK_SECONDS = 21600;

    public function __construct(
        private readonly DetachedProcessLauncher $launcher,
        private readonly DeploymentRunHistory $history,
    ) {}

    /**
     * @param  list<string>  $keys
     * @return list<string>
     */
    public function launch(array $keys): array
    {
        $runId = (string) Str::uuid();
        $lock = Cache::lock(self::LOCK_KEY, self::LOCK_SECONDS, $runId);

        if (! $lock->get()) {
            return [(string) __('Warning: another software update is already running.')];
        }

        $line = (string) __('Software update scheduled in a detached process. This page will keep showing its durable progress if web workers restart.');
        $this->history->beginDeploymentRun($runId, $keys, $line);

        $command = PhpCli::current()->artisan([
            'blb:software:update',
            '--run-id='.$runId,
            ...$keys,
        ]);
        $log = storage_path('logs/software-update-'.$runId.'.log');

        if ($this->launcher->launch($command, base_path(), [], $log, $log)) {
            return [$line];
        }

        $lock->release();
        $failure = (string) __('FAILED: software update process could not be started.');
        $this->history->finishDeploymentRun($runId, 'error', [$failure]);

        return [$failure];
    }

    public function inProgress(): bool
    {
        $probe = Cache::lock(self::LOCK_KEY, 1);

        if (! $probe->get()) {
            return true;
        }

        $probe->release();

        return false;
    }

    public function maintenanceActionLock(): Lock
    {
        return Cache::lock(self::LOCK_KEY, self::LOCK_SECONDS);
    }
}
