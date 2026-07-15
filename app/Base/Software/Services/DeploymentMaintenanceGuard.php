<?php

namespace App\Base\Software\Services;

use App\Base\Support\DetachedProcessLauncher;
use App\Base\Support\PhpCli;
use Illuminate\Support\Facades\Artisan;
use RuntimeException;

class DeploymentMaintenanceGuard
{
    public const LEASE_SECONDS = 1800;

    public function __construct(private readonly DetachedProcessLauncher $launcher) {}

    public function arm(string $runId): void
    {
        $this->writeLease($runId, false);
        $log = storage_path('logs/software-update-watchdog-'.$runId.'.log');
        $started = $this->launcher->launch(
            PhpCli::current()->artisan(['blb:software:update-watchdog', $runId]),
            base_path(),
            [],
            $log,
            $log,
        );

        if (! $started || ! $this->waitUntilArmed($runId)) {
            $this->disarm($runId);

            throw new RuntimeException('The maintenance recovery watchdog could not be armed.');
        }
    }

    public function renew(string $runId): bool
    {
        $renewed = false;

        $this->mutateLease($runId, function (array $lease) use (&$renewed): array {
            if (($lease['state'] ?? 'active') === 'active') {
                $lease['armed'] = true;
                $lease['expires_at'] = time() + self::LEASE_SECONDS;
                $renewed = true;
            }

            return $lease;
        });

        return $renewed;
    }

    public function enter(string $runId): void
    {
        if (app()->isDownForMaintenance()) {
            throw new RuntimeException('Belimbing was already in maintenance mode before the update started.');
        }

        if (Artisan::call('down', ['--retry' => 5]) !== 0) {
            throw new RuntimeException('Belimbing could not enter maintenance mode.');
        }

        $mode = app()->maintenanceMode();
        $mode->activate(array_merge($mode->data(), ['blb_software_update_run_id' => $runId]));

        if (! $this->renew($runId)) {
            throw new RuntimeException('The maintenance recovery lease was lost.');
        }
    }

    public function leave(string $runId): bool
    {
        if (! $this->ownsMaintenance($runId)) {
            return ! app()->isDownForMaintenance();
        }

        return Artisan::call('up') === 0
            && ! app()->isDownForMaintenance();
    }

    public function ownsMaintenance(string $runId): bool
    {
        return app()->isDownForMaintenance()
            && (app()->maintenanceMode()->data()['blb_software_update_run_id'] ?? null) === $runId;
    }

    public function activeRunId(): ?string
    {
        if (! app()->isDownForMaintenance()) {
            return null;
        }

        $runId = app()->maintenanceMode()->data()['blb_software_update_run_id'] ?? null;

        return is_string($runId) && $runId !== '' ? $runId : null;
    }

    public function acknowledgeWatchdog(string $runId): bool
    {
        $acknowledged = false;

        $this->mutateLease($runId, function (array $lease) use (&$acknowledged): array {
            if (($lease['state'] ?? 'active') === 'active') {
                $lease['armed'] = true;
                $lease['expires_at'] = time() + self::LEASE_SECONDS;
                $acknowledged = true;
            }

            return $lease;
        });

        return $acknowledged;
    }

    public function leaseExpired(string $runId): bool
    {
        $lease = $this->lease($runId);

        return ($lease['run_id'] ?? null) === $runId
            && is_int($lease['expires_at'] ?? null)
            && $lease['expires_at'] <= time();
    }

    public function leaseExists(string $runId): bool
    {
        return ($this->lease($runId)['run_id'] ?? null) === $runId;
    }

    public function recoverExpired(string $runId, DeploymentRunHistory $history): bool
    {
        $claimed = false;

        $this->mutateLease($runId, function (array $lease) use (&$claimed): array {
            if (in_array($lease['state'] ?? 'active', ['active', 'recovering'], true)
                && is_int($lease['expires_at'] ?? null)
                && $lease['expires_at'] <= time()) {
                $lease['state'] = 'recovering';
                $lease['expires_at'] = time() + 30;
                $claimed = true;
            }

            return $lease;
        });

        if (! $claimed) {
            return false;
        }

        $ownedMaintenance = $this->ownsMaintenance($runId);

        if ($ownedMaintenance) {
            if (! $this->leave($runId)) {
                $this->retryRecovery($runId);

                return false;
            }
        }

        $history->interruptDeploymentRun(
            $runId,
            $ownedMaintenance
                ? (string) __('FAILED: the update process stopped responding; automatic recovery brought Belimbing back online.')
                : (string) __('FAILED: the update process stopped responding before completion.'),
        );

        $this->disarm($runId);

        return true;
    }

    public function disarm(string $runId): void
    {
        if ($this->mutateLease($runId, function (array $lease): array {
            $lease['state'] = 'complete';

            return $lease;
        }) !== null) {
            @unlink($this->leasePath($runId));
        }
    }

    /** @return array<string, mixed> */
    private function lease(string $runId): array
    {
        $handle = @fopen($this->leasePath($runId), 'rb');

        if ($handle === false) {
            return [];
        }

        try {
            if (! flock($handle, LOCK_SH)) {
                return [];
            }

            $contents = stream_get_contents($handle);
            flock($handle, LOCK_UN);
        } finally {
            fclose($handle);
        }

        $lease = is_string($contents) ? json_decode($contents, true) : null;

        return is_array($lease) ? $lease : [];
    }

    private function writeLease(string $runId, bool $armed, ?int $expiresAt = null): void
    {
        $path = $this->leasePath($runId);

        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0775, true);
        }

        file_put_contents($path, json_encode([
            'run_id' => $runId,
            'armed' => $armed,
            'state' => 'active',
            'expires_at' => $expiresAt ?? time() + self::LEASE_SECONDS,
        ], JSON_THROW_ON_ERROR), LOCK_EX);
    }

    /**
     * @param  callable(array<string, mixed>): array<string, mixed>  $mutation
     * @return array<string, mixed>|null
     */
    private function mutateLease(string $runId, callable $mutation): ?array
    {
        $handle = @fopen($this->leasePath($runId), 'r+b');

        if ($handle === false) {
            return null;
        }

        try {
            if (! flock($handle, LOCK_EX)) {
                return null;
            }

            $contents = stream_get_contents($handle);
            $lease = is_string($contents) ? json_decode($contents, true) : null;

            if (! is_array($lease) || ($lease['run_id'] ?? null) !== $runId) {
                flock($handle, LOCK_UN);

                return null;
            }

            $lease = $mutation($lease);
            rewind($handle);
            ftruncate($handle, 0);
            fwrite($handle, json_encode($lease, JSON_THROW_ON_ERROR));
            fflush($handle);
            flock($handle, LOCK_UN);

            return $lease;
        } finally {
            fclose($handle);
        }
    }

    private function retryRecovery(string $runId): void
    {
        $this->mutateLease($runId, function (array $lease): array {
            $lease['state'] = 'active';
            $lease['expires_at'] = time() + 10;

            return $lease;
        });
    }

    private function waitUntilArmed(string $runId): bool
    {
        $deadline = microtime(true) + 5;

        while (microtime(true) < $deadline) {
            if (($this->lease($runId)['armed'] ?? false) === true) {
                return true;
            }

            usleep(100_000);
        }

        return false;
    }

    private function leasePath(string $runId): string
    {
        return storage_path('framework/software-update-'.$runId.'.lease');
    }
}
