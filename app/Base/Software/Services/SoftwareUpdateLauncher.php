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
        private readonly DeploymentService $deployment,
    ) {}

    /**
     * @param  list<string>  $keys
     * @return list<string>
     */
    public function launch(array $keys): array
    {
        $unpushedSources = $this->unpushedSources($keys);

        if ($unpushedSources !== []) {
            return [(string) __('FAILED: software update was not started because these sources have local commits that are not on their remotes: :sources. Push or reconcile those commits outside Belimbing, refresh source status, then retry.', [
                'sources' => implode(', ', $unpushedSources),
            ])];
        }

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

    /**
     * Recover the reservation left behind when a detached update never began.
     *
     * Callers must first prove that the durable run record is an abandoned
     * scheduling-only update; force-releasing a live update lock would allow
     * concurrent migrations.
     */
    public function releaseStaleUpdateLock(): void
    {
        Cache::lock(self::LOCK_KEY)->forceRelease();
    }

    public function maintenanceActionLock(): Lock
    {
        return Cache::lock(self::LOCK_KEY, self::LOCK_SECONDS);
    }

    /**
     * @param  list<string>  $keys
     * @return list<string>
     */
    private function unpushedSources(array $keys): array
    {
        $selectedKeys = array_fill_keys($keys, true);
        $sources = [];

        foreach ($this->deployment->localStatus() as $source) {
            if ($selectedKeys !== [] && ! isset($selectedKeys[$source['key']])) {
                continue;
            }

            $ahead = (int) ($source['working_tree']['ahead'] ?? 0);

            if ($ahead < 1) {
                continue;
            }

            $sources[] = (string) __(':label (:count unpushed)', [
                'label' => $source['label'],
                'count' => $ahead,
            ]);
        }

        return $sources;
    }
}
