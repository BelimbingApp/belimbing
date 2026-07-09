<?php

namespace App\Base\Software\Services;

use App\Base\Authz\Contracts\AuthorizationService;
use App\Base\Authz\DTO\Actor;
use App\Base\Foundation\Enums\StatusVariant;
use App\Base\System\Contracts\StatusBarDiagnosticProvider;
use App\Base\System\DTO\StatusBarDiagnostic;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route;

final class FrankenPhpWorkerStatusDiagnosticProvider implements StatusBarDiagnosticProvider
{
    public function __construct(
        private readonly AuthorizationService $authorizationService,
        private readonly DeploymentRunHistory $history,
    ) {}

    /**
     * @return iterable<int, StatusBarDiagnostic>
     */
    public function diagnosticsFor(Authenticatable $user): iterable
    {
        if (! $this->canManageUpdates($user)) {
            return [];
        }

        $reloadState = $this->history->reloadState();

        if ($this->reloadStateIsPending($reloadState)) {
            if ($this->history->reloadStateIsStale($reloadState)) {
                return [$this->stalledReloadDiagnostic($reloadState)];
            }

            return [$this->pendingReloadDiagnostic($reloadState)];
        }

        if ($reloadState === null) {
            $pendingSince = $this->pendingReloadSince();

            if ($pendingSince !== null) {
                return [$this->pendingReloadDiagnostic([
                    'attempted_at' => $pendingSince,
                    'status' => 'pending',
                    'message' => (string) __('Runtime reload is pending.'),
                    'admin_url' => null,
                ])];
            }
        }

        $lastReload = $this->history->lastReload();

        if ($lastReload !== null && $lastReload['ok'] === false) {
            return [$this->failedReloadDiagnostic($lastReload)];
        }

        return [];
    }

    /**
     * @param  array{attempted_at: string, status: string, message: string, admin_url: string|null}  $reloadState
     */
    private function pendingReloadDiagnostic(array $reloadState): StatusBarDiagnostic
    {
        return new StatusBarDiagnostic(
            id: 'software.frankenphp-worker-reload.pending',
            severity: StatusVariant::Warning,
            source: __('Updates'),
            summary: __('FrankenPHP worker reload pending'),
            detail: __('A worker reload was scheduled after a domain or software change. BLB will record the final result when the background reload finishes.'),
            target: $this->updatesUrl(),
            metadata: [
                'pending_since' => $reloadState['attempted_at'],
                'status' => $reloadState['status'],
                'message' => $reloadState['message'],
            ],
        );
    }

    /**
     * @param  array{attempted_at: string, status: string, message: string, admin_url: string|null}  $reloadState
     */
    private function stalledReloadDiagnostic(array $reloadState): StatusBarDiagnostic
    {
        return new StatusBarDiagnostic(
            id: 'software.frankenphp-worker-reload.stalled',
            severity: StatusVariant::Error,
            source: __('Updates'),
            summary: __('FrankenPHP worker reload needs attention'),
            detail: __('A worker reload was scheduled but no completion was recorded. Running workers may still be serving old code, or the background reload command may have failed.'),
            target: $this->updatesUrl(),
            metadata: [
                'pending_since' => $reloadState['attempted_at'],
                'status' => $reloadState['status'],
                'message' => $reloadState['message'],
            ],
        );
    }

    /**
     * @param  array{attempted_at: string, ok: bool, message: string, admin_url: string|null}  $lastReload
     */
    private function failedReloadDiagnostic(array $lastReload): StatusBarDiagnostic
    {
        return new StatusBarDiagnostic(
            id: 'software.frankenphp-worker-reload.failed',
            severity: StatusVariant::Error,
            source: __('Updates'),
            summary: __('FrankenPHP worker reload needs attention'),
            detail: __('The last worker reload did not complete. If migrations or code updates already ran, web workers may still be serving old code until FrankenPHP reloads.'),
            target: $this->updatesUrl(),
            metadata: [
                'attempted_at' => $lastReload['attempted_at'],
                'message' => $lastReload['message'],
                'admin_url' => $lastReload['admin_url'],
            ],
        );
    }

    private function pendingReloadSince(): ?string
    {
        try {
            $pending = Cache::get(FrankenPhpDomainRuntimeReloader::PENDING_CACHE_KEY);
        } catch (\Throwable) {
            return null;
        }

        return is_string($pending) && $pending !== '' ? $pending : null;
    }

    /**
     * @param  array{attempted_at: string, status: string, message: string, admin_url: string|null}|null  $reloadState
     */
    private function reloadStateIsPending(?array $reloadState): bool
    {
        return in_array($reloadState['status'] ?? null, ['pending', 'running'], true);
    }

    private function updatesUrl(): ?string
    {
        return Route::has('admin.system.software.updates.index')
            ? route('admin.system.software.updates.index')
            : null;
    }

    private function canManageUpdates(Authenticatable $user): bool
    {
        try {
            return $this->authorizationService
                ->can(Actor::forUser($user), 'admin.system.software.updates.manage')
                ->allowed;
        } catch (\Throwable) {
            return false;
        }
    }
}
