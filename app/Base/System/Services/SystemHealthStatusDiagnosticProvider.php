<?php

namespace App\Base\System\Services;

use App\Base\Authz\Contracts\AuthorizationService;
use App\Base\Authz\DTO\Actor;
use App\Base\Foundation\Enums\StatusVariant;
use App\Base\System\Contracts\StatusBarDiagnosticProvider;
use App\Base\System\DTO\StatusBarDiagnostic;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Route;

final class SystemHealthStatusDiagnosticProvider implements StatusBarDiagnosticProvider
{
    public function __construct(
        private readonly AuthorizationService $authorizationService,
        private readonly SystemHealthProbe $health,
    ) {}

    /**
     * @return iterable<int, StatusBarDiagnostic>
     */
    public function diagnosticsFor(Authenticatable $user): iterable
    {
        if (! $this->canViewSystemInfo($user)) {
            return [];
        }

        $unwritable = $this->health->unwritablePaths();

        if ($unwritable === []) {
            return [];
        }

        return [$this->filesystemDiagnostic($unwritable)];
    }

    /**
     * @param  list<array{key: string, label: string, path: string, exists: bool, writable: bool}>  $unwritable
     */
    private function filesystemDiagnostic(array $unwritable): StatusBarDiagnostic
    {
        return new StatusBarDiagnostic(
            id: 'system.filesystem-unwritable',
            severity: StatusVariant::Error,
            source: __('System'),
            summary: trans_choice(':count required filesystem path is not writable|:count required filesystem paths are not writable', count($unwritable), [
                'count' => count($unwritable),
            ]),
            detail: __('PHP cannot write to required runtime paths. Open System Info to inspect filesystem health.'),
            target: $this->systemInfoUrl(),
            metadata: [
                'paths' => array_map(fn (array $path): string => $path['label'], $unwritable),
            ],
        );
    }

    private function systemInfoUrl(): ?string
    {
        return Route::has('admin.system.info.index')
            ? route('admin.system.info.index')
            : null;
    }

    private function canViewSystemInfo(Authenticatable $user): bool
    {
        try {
            return $this->authorizationService
                ->can(Actor::forUser($user), 'admin.system.info.view')
                ->allowed;
        } catch (\Throwable) {
            return false;
        }
    }
}
