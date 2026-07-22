<?php

namespace App\Base\Software\Services;

use App\Base\Authz\Contracts\AuthorizationService;
use App\Base\Authz\DTO\Actor;
use App\Base\Foundation\Enums\StatusVariant;
use App\Base\System\Contracts\StatusBarDiagnosticProvider;
use App\Base\System\DTO\StatusBarDiagnostic;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Route;

final class PhpExtensionDriftStatusDiagnosticProvider implements StatusBarDiagnosticProvider
{
    public function __construct(
        private readonly AuthorizationService $authorizationService,
        private readonly PhpExtensionDriftProbe $probe,
    ) {}

    /**
     * @return iterable<int, StatusBarDiagnostic>
     */
    public function diagnosticsFor(Authenticatable $user): iterable
    {
        if (! $this->canManageUpdates($user)) {
            return [];
        }

        // Uncached on purpose: this only reads a couple of already-resident
        // ini files (no subprocess, no nested git scan), and caching would
        // keep reporting drift for up to the TTL right after an operator
        // restarts the process to fix it.
        $missing = $this->probe->missingExtensions();

        if ($missing === []) {
            return [];
        }

        return [$this->diagnostic($missing)];
    }

    /**
     * @param  list<string>  $missing
     */
    private function diagnostic(array $missing): StatusBarDiagnostic
    {
        sort($missing);

        return new StatusBarDiagnostic(
            id: 'software.php-extension-drift',
            severity: StatusVariant::Error,
            source: __('Software'),
            summary: trans_choice(
                ':count PHP extension enabled in php.ini is not loaded|:count PHP extensions enabled in php.ini are not loaded',
                count($missing),
                ['count' => count($missing)],
            ),
            detail: __('Reloading FrankenPHP workers will not fix this — extensions load once when the process starts. Open Updates for host restart instructions.'),
            target: $this->updatesUrl(),
            metadata: [
                'extensions' => $missing,
            ],
        );
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
