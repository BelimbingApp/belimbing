<?php

namespace App\Base\Software\Services;

use App\Base\Settings\Contracts\SettingsService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * Stores the durable run records shown on the Deployment update page.
 */
class DeploymentRunHistory
{
    public const RELOAD_STALE_AFTER_MINUTES = 5;

    public const UPDATE_STARTUP_TIMEOUT_SECONDS = 15;

    private const LAST_RELOAD_KEY = 'system.update.frankenphp.last_reload';

    private const RELOAD_STATE_KEY = 'system.update.frankenphp.reload_state';

    private const COMPOSER_RUN_KEY = 'system.update.composer.last_run';

    private const FRONTEND_RUN_KEY = 'system.update.frontend.last_run';

    private const DEPLOYMENT_RUN_KEY = 'system.update.deployment.last_run';

    private const DEPLOYMENT_RUN_LOCK_KEY = 'software.deployment.run-history';

    public function __construct(private readonly SettingsService $settings) {}

    public function rememberComposerRun(bool $ok, string $message): void
    {
        $this->rememberRun(self::COMPOSER_RUN_KEY, $ok, $message);
    }

    public function rememberFrontendRun(bool $ok, string $message, string $packageManager): void
    {
        $this->rememberRun(self::FRONTEND_RUN_KEY, $ok, $message, ['pm' => $packageManager]);
    }

    public function rememberReloadScheduled(string $message): void
    {
        $this->rememberReloadState('pending', $message);
    }

    public function rememberReloadRunning(string $message): void
    {
        $this->rememberReloadState('running', $message);
    }

    public function rememberReload(bool $ok, string $message, string $adminUrl): void
    {
        $this->rememberRun(self::LAST_RELOAD_KEY, $ok, $message, ['admin_url' => $adminUrl]);
        $this->rememberReloadState($ok ? 'success' : 'failed', $message, $adminUrl);
    }

    /**
     * @return array{attempted_at: string, ok: bool, message: string, admin_url: string}|null
     */
    public function lastReload(): ?array
    {
        return $this->readRun(self::LAST_RELOAD_KEY, ['admin_url' => true]);
    }

    /**
     * @return array{attempted_at: string, status: string, message: string, admin_url: string|null}|null
     */
    public function reloadState(): ?array
    {
        $record = $this->settings->get(self::RELOAD_STATE_KEY);

        if (! is_array($record)) {
            return null;
        }

        $attemptedAt = $record['attempted_at'] ?? null;
        $status = $record['status'] ?? null;
        $message = $record['message'] ?? null;

        if (! is_string($attemptedAt) || ! is_string($status) || ! is_string($message)) {
            return null;
        }

        $adminUrl = $record['admin_url'] ?? null;

        return [
            'attempted_at' => $attemptedAt,
            'status' => $status,
            'message' => $message,
            'admin_url' => is_string($adminUrl) ? $adminUrl : null,
        ];
    }

    /**
     * @param  array{attempted_at?: string, status?: string, message?: string, admin_url?: string|null}|null  $reloadState
     */
    public function reloadStateIsStale(?array $reloadState = null): bool
    {
        if (! in_array($reloadState['status'] ?? null, ['pending', 'running'], true)) {
            return false;
        }

        return $this->timestampIsStale($reloadState['attempted_at'] ?? null);
    }

    /**
     * Is a detached worker reload still expected to report back? Pending or running
     * and not yet stale means a process is out there doing the work.
     */
    public function reloadIsInProgress(): bool
    {
        $state = $this->reloadState();

        return in_array($state['status'] ?? null, ['pending', 'running'], true)
            && ! $this->reloadStateIsStale($state);
    }

    private function timestampIsStale(mixed $attemptedAt): bool
    {
        if (! is_string($attemptedAt) || $attemptedAt === '') {
            return true;
        }

        try {
            return Carbon::parse($attemptedAt)
                ->lessThan(now()->subMinutes(self::RELOAD_STALE_AFTER_MINUTES));
        } catch (\Throwable) {
            return true;
        }
    }

    /**
     * @return array{attempted_at: string, ok: bool, message: string, pm: string|null}|null
     */
    public function lastComposerRun(): ?array
    {
        return $this->readRun(self::COMPOSER_RUN_KEY, ['pm' => false]);
    }

    /**
     * @return array{attempted_at: string, ok: bool, message: string, pm: string|null}|null
     */
    public function lastFrontendRun(): ?array
    {
        return $this->readRun(self::FRONTEND_RUN_KEY, ['pm' => false]);
    }

    /**
     * Record the run shown in the Deployment page's run box so its outcome and time
     * survive a page reload or a brand-new session.
     *
     * Pass $runId when the work continues in a detached process: the record is then
     * closable by whoever knows that id, so the background job can finish it through
     * completeDeploymentRun() instead of leaving the box on "in progress" forever.
     *
     * @param  list<string>  $log
     */
    public function rememberDeploymentRun(array $log, string $status, ?string $runId = null): void
    {
        $this->withDeploymentRunLock(function () use ($log, $status, $runId): void {
            // A detached job that already finished must not be dragged back to
            // pending by the slower synchronous write that scheduled it.
            $existing = $runId === null ? null : $this->deploymentRunRecord($runId);

            if ($existing !== null && $this->deploymentRunIsTerminal($existing)) {
                return;
            }

            $record = [
                'attempted_at' => now()->utc()->toIso8601String(),
                'status' => $status,
                'summary' => $log === [] ? '' : (string) $log[array_key_last($log)],
                'log' => array_values($log),
            ];

            if ($runId !== null) {
                $record['run_id'] = $runId;
                $record['phase'] = 'running';
            }

            $this->settings->set(self::DEPLOYMENT_RUN_KEY, $record);
        });
    }

    /**
     * Close a run whose work finished in a detached process, keeping the lines the
     * scheduling request already recorded and appending how it actually ended.
     *
     * No-ops when the stored record belongs to a different run (or to none at all),
     * so a background reload can never overwrite an unrelated run's result.
     *
     * @param  list<string>  $lines
     */
    public function completeDeploymentRun(string $runId, string $status, array $lines): void
    {
        $this->withDeploymentRunLock(function () use ($runId, $status, $lines): void {
            $record = $this->deploymentRunRecord($runId);

            if ($record === null || $this->deploymentRunIsTerminal($record)) {
                return;
            }

            $log = is_array($record['log'] ?? null)
                ? array_values(array_filter($record['log'], 'is_string'))
                : [];

            $this->finishDeploymentRunUnlocked(
                $runId,
                $status,
                array_merge($log, array_values(array_filter($lines, 'is_string'))),
            );
        });
    }

    /** @param list<string> $targetKeys */
    public function beginDeploymentRun(string $runId, array $targetKeys, string $message): void
    {
        $now = now()->utc()->toIso8601String();

        $this->withDeploymentRunLock(function () use ($runId, $targetKeys, $message, $now): void {
            $this->settings->set(self::DEPLOYMENT_RUN_KEY, [
                'run_id' => $runId,
                'target_keys' => array_values($targetKeys),
                'attempted_at' => $now,
                'updated_at' => $now,
                'status' => 'pending',
                'phase' => 'scheduled',
                'startup_deadline_at' => now()->utc()->addSeconds(self::UPDATE_STARTUP_TIMEOUT_SECONDS)->toIso8601String(),
                'summary' => $message,
                'log' => [$message],
            ]);
        });
    }

    public function acknowledgeDeploymentRunStart(string $runId): bool
    {
        return $this->transitionDeploymentRunPhase(
            $runId,
            'scheduled',
            'starting',
            (string) __('Detached update process acknowledged startup.'),
        );
    }

    public function markDeploymentRunRunning(string $runId): bool
    {
        return $this->transitionDeploymentRunPhase(
            $runId,
            'starting',
            'running',
            (string) __('Detached update process started; automatic maintenance recovery is armed.'),
        );
    }

    /**
     * Atomically prove that an exact run no longer needs its launch reservation.
     *
     * A rejected phase transition alone is insufficient: another child with the
     * same transferable lock token may already be running. The reservation is
     * releasable only after durable state proves this run is gone, finished, or
     * superseded by a different run id.
     */
    public function deploymentRunReservationIsObsolete(string $runId): bool
    {
        return $this->withDeploymentRunLock(function () use ($runId): bool {
            $record = $this->settings->get(self::DEPLOYMENT_RUN_KEY);

            return ! is_array($record)
                || ($record['run_id'] ?? null) !== $runId
                || $this->deploymentRunIsTerminal($record);
        });
    }

    public function appendDeploymentLine(string $runId, string $line): void
    {
        $this->withDeploymentRunLock(function () use ($runId, $line): void {
            $record = $this->deploymentRunRecord($runId);

            if ($record === null || $this->deploymentRunIsTerminal($record)) {
                return;
            }

            $log = is_array($record['log'] ?? null) ? array_values(array_filter($record['log'], 'is_string')) : [];
            $log[] = $line;
            $record['updated_at'] = now()->utc()->toIso8601String();
            $record['summary'] = $line;
            $record['log'] = array_slice($log, -300);
            $this->settings->set(self::DEPLOYMENT_RUN_KEY, $record);
        });
    }

    /** @param list<string> $log */
    public function finishDeploymentRun(string $runId, string $status, array $log): void
    {
        $this->withDeploymentRunLock(fn () => $this->finishDeploymentRunUnlocked($runId, $status, $log));
    }

    public function interruptDeploymentRun(string $runId, string $message): bool
    {
        return $this->withDeploymentRunLock(function () use ($runId, $message): bool {
            $record = $this->deploymentRunRecord($runId);

            if ($record === null || $this->deploymentRunIsTerminal($record)) {
                return false;
            }

            $log = is_array($record['log'] ?? null) ? array_values(array_filter($record['log'], 'is_string')) : [];
            $log[] = $message;
            $this->finishDeploymentRunUnlocked($runId, 'error', $log);

            return true;
        });
    }

    /**
     * Atomically fail a child that never acknowledged startup.
     *
     * Returning the exact run id is the caller's proof that it may release the
     * launch reservation. A concurrent child acknowledgement uses the same lock,
     * so only one of the two transitions can win.
     */
    public function failExpiredScheduledUpdate(): ?string
    {
        return $this->withDeploymentRunLock(function (): ?string {
            $record = $this->settings->get(self::DEPLOYMENT_RUN_KEY);
            $legacyScheduled = is_array($record) && $this->isLegacySchedulingOnlyUpdate($record);

            if (! is_array($record)
                || ($record['status'] ?? null) !== 'pending'
                || (($record['phase'] ?? null) !== 'scheduled' && ! $legacyScheduled)
                || ($legacyScheduled
                    ? ! $this->pendingRunLooksAbandoned($record)
                    : ! $this->startupDeadlineExpired($record['startup_deadline_at'] ?? null))) {
                return null;
            }

            $runId = $record['run_id'] ?? null;

            if (! is_string($runId) || $runId === '') {
                return null;
            }

            $failure = (string) __('FAILED: detached update run :run did not acknowledge startup. See storage/logs/software-update-:run.log for the child diagnostic.', [
                'run' => $runId,
            ]);
            $log = is_array($record['log'] ?? null)
                ? array_values(array_filter($record['log'], 'is_string'))
                : [];
            $log[] = $failure;
            $this->finishDeploymentRunUnlocked($runId, 'error', $log);

            return $runId;
        });
    }

    /**
     * Close a pending run that nothing is going to finish.
     *
     * The run box only says "in progress" on the word of a detached process that
     * promised to come back with a result. When no such process is alive and the
     * record has been silent past the staleness window, that promise was broken —
     * something killed the process between its last line and its result — and
     * claiming "in progress" forever is a lie the operator cannot act on. It also
     * covers records written before runs carried an id, which nothing can close.
     *
     * $ownerActive is whether a process that could still close this run is known to
     * be running: a detached update holding the launcher lock, or a live reload.
     * Callers own that check because the launcher depends on this class, not the
     * other way around.
     */
    public function abandonStalePendingRun(bool $ownerActive): void
    {
        // Read before locking: on nearly every page view the run is already
        // terminal, and taking the run lock per render would be pure overhead.
        if ($ownerActive || ! $this->pendingRunLooksAbandoned()) {
            return;
        }

        $this->withDeploymentRunLock(function (): void {
            $record = $this->settings->get(self::DEPLOYMENT_RUN_KEY);

            if (! is_array($record) || ! $this->pendingRunLooksAbandoned($record)) {
                return;
            }

            $log = is_array($record['log'] ?? null)
                ? array_values(array_filter($record['log'], 'is_string'))
                : [];
            $log[] = (string) __('FAILED: this run never reported a result and the process doing the work is no longer running. Check the log above, then run it again.');

            $now = now()->utc()->toIso8601String();
            $record['updated_at'] = $now;
            $record['finished_at'] = $now;
            $record['status'] = 'error';
            $record['summary'] = $log[array_key_last($log)];
            $record['log'] = array_slice($log, -300);
            $this->settings->set(self::DEPLOYMENT_RUN_KEY, $record);
        });
    }

    /**
     * @param  array<string, mixed>|null  $record
     */
    private function pendingRunLooksAbandoned(?array $record = null): bool
    {
        $record ??= $this->settings->get(self::DEPLOYMENT_RUN_KEY);

        if (! is_array($record) || ($record['status'] ?? null) !== 'pending') {
            return false;
        }

        // appendDeploymentLine keeps updated_at fresh, so a run that is still
        // talking to us is never mistaken for an abandoned one.
        return $this->timestampIsStale($record['updated_at'] ?? $record['attempted_at'] ?? null);
    }

    /**
     * @return array{run_id: string|null, attempted_at: string, status: string, phase: string|null, summary: string, log: list<string>}|null
     */
    public function lastDeploymentRun(): ?array
    {
        $record = $this->settings->get(self::DEPLOYMENT_RUN_KEY);

        if (! is_array($record)) {
            return null;
        }

        $attemptedAt = $record['attempted_at'] ?? null;
        $status = $record['status'] ?? null;

        if (! is_string($attemptedAt) || ! is_string($status)) {
            return null;
        }

        return [
            'run_id' => is_string($record['run_id'] ?? null) ? $record['run_id'] : null,
            'attempted_at' => $attemptedAt,
            'status' => $status,
            'phase' => is_string($record['phase'] ?? null) ? $record['phase'] : null,
            'summary' => is_string($record['summary'] ?? null) ? $record['summary'] : '',
            'log' => array_values(array_filter(
                is_array($record['log'] ?? null) ? $record['log'] : [],
                'is_string',
            )),
        ];
    }

    /** @return array<string, mixed>|null */
    private function deploymentRunRecord(string $runId): ?array
    {
        $record = $this->settings->get(self::DEPLOYMENT_RUN_KEY);

        return is_array($record) && ($record['run_id'] ?? null) === $runId ? $record : null;
    }

    /** @param array<string, mixed> $record */
    private function deploymentRunIsTerminal(array $record): bool
    {
        return in_array($record['status'] ?? null, ['success', 'warning', 'error'], true);
    }

    private function transitionDeploymentRunPhase(string $runId, string $from, string $to, string $line): bool
    {
        return $this->withDeploymentRunLock(function () use ($runId, $from, $to, $line): bool {
            $record = $this->deploymentRunRecord($runId);

            if ($record === null
                || $this->deploymentRunIsTerminal($record)
                || ($record['phase'] ?? null) !== $from) {
                return false;
            }

            $log = is_array($record['log'] ?? null)
                ? array_values(array_filter($record['log'], 'is_string'))
                : [];
            $log[] = $line;
            $record['phase'] = $to;
            $record['updated_at'] = now()->utc()->toIso8601String();
            $record['summary'] = $line;
            $record['log'] = array_slice($log, -300);
            $this->settings->set(self::DEPLOYMENT_RUN_KEY, $record);

            return true;
        });
    }

    private function startupDeadlineExpired(mixed $deadline): bool
    {
        if (! is_string($deadline) || $deadline === '') {
            return false;
        }

        try {
            return Carbon::parse($deadline)->lessThanOrEqualTo(now());
        } catch (\Throwable) {
            return true;
        }
    }

    /** @param array<string, mixed> $record */
    private function isLegacySchedulingOnlyUpdate(array $record): bool
    {
        if (isset($record['phase'])) {
            return false;
        }

        $log = is_array($record['log'] ?? null)
            ? array_values(array_filter($record['log'], 'is_string'))
            : [];

        return count($log) === 1
            && str_starts_with($log[0], 'Software update scheduled in a detached process.');
    }

    /** @param list<string> $log */
    private function finishDeploymentRunUnlocked(string $runId, string $status, array $log): void
    {
        $record = $this->deploymentRunRecord($runId);

        if ($record === null || $this->deploymentRunIsTerminal($record)) {
            return;
        }

        $log = array_slice(array_values(array_filter($log, 'is_string')), -300);
        $now = now()->utc()->toIso8601String();
        $record['updated_at'] = $now;
        $record['finished_at'] = $now;
        $record['status'] = $status;
        $record['phase'] = 'finished';
        $record['summary'] = $log === [] ? '' : $log[array_key_last($log)];
        $record['log'] = $log;
        $this->settings->set(self::DEPLOYMENT_RUN_KEY, $record);
    }

    private function withDeploymentRunLock(callable $callback): mixed
    {
        return Cache::lock(self::DEPLOYMENT_RUN_LOCK_KEY, 10)->block(5, $callback);
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function rememberRun(string $key, bool $ok, string $message, array $extra = []): void
    {
        $this->settings->set($key, array_merge([
            'attempted_at' => now()->utc()->toIso8601String(),
            'ok' => $ok,
            'message' => $message,
        ], $extra));
    }

    private function rememberReloadState(string $status, string $message, ?string $adminUrl = null): void
    {
        $this->settings->set(self::RELOAD_STATE_KEY, [
            'attempted_at' => now()->utc()->toIso8601String(),
            'status' => $status,
            'message' => $message,
            'admin_url' => $adminUrl,
        ]);
    }

    /**
     * @param  array<string, bool>  $stringFields  field => required
     * @return array<string, bool|string|null>|null
     */
    private function readRun(string $key, array $stringFields = []): ?array
    {
        $record = $this->settings->get($key);
        $attemptedAt = is_array($record) ? ($record['attempted_at'] ?? null) : null;
        $message = is_array($record) ? ($record['message'] ?? null) : null;

        if (! is_array($record) || ! is_string($attemptedAt) || ! is_string($message)) {
            return null;
        }

        $run = [
            'attempted_at' => $attemptedAt,
            'ok' => ($record['ok'] ?? false) === true,
            'message' => $message,
        ];

        foreach ($stringFields as $field => $required) {
            $value = $record[$field] ?? null;

            if ($required && ! is_string($value)) {
                return null;
            }

            $run[$field] = is_string($value) ? $value : null;
        }

        return $run;
    }
}
