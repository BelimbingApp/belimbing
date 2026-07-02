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

        $pendingSince = $this->pendingReloadSince();

        if ($pendingSince !== null) {
            return [$this->pendingReloadDiagnostic($pendingSince)];
        }

        $lastReload = $this->history->lastReload();

        if ($lastReload !== null && $lastReload['ok'] === false) {
            return [$this->failedReloadDiagnostic($lastReload)];
        }

        return [];
    }

    private function pendingReloadDiagnostic(string $pendingSince): StatusBarDiagnostic
    {
        return new StatusBarDiagnostic(
            id: 'software.frankenphp-worker-reload.pending',
            severity: StatusVariant::Warning,
            source: __('Updates'),
            summary: __('FrankenPHP worker reload pending'),
            detail: __('A worker reload was scheduled after a domain or software change. Running workers may keep old code until the reload finishes.'),
            target: $this->updatesUrl(),
            metadata: [
                'pending_since' => $pendingSince,
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
