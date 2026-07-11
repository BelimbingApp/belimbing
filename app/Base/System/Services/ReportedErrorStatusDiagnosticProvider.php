<?php

namespace App\Base\System\Services;

use App\Base\Authz\Contracts\AuthorizationService;
use App\Base\Authz\DTO\Actor;
use App\Base\Foundation\Enums\StatusVariant;
use App\Base\System\Contracts\StatusBarDiagnosticProvider;
use App\Base\System\DTO\StatusBarDiagnostic;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Route;

/**
 * Surfaces recently reported application errors in the status-bar
 * diagnostic bubble. Generic by design: anything that reaches the
 * exception handler's reportable pipeline (authz config rejections,
 * import failures, uncaught 500s, queue and console errors) shows up
 * here without per-module wiring — errors must never be log-file-only.
 */
final class ReportedErrorStatusDiagnosticProvider implements StatusBarDiagnosticProvider
{
    public function __construct(
        private readonly AuthorizationService $authorizationService,
        private readonly ReportedErrorRecorder $recorder,
    ) {}

    /**
     * @return iterable<int, StatusBarDiagnostic>
     */
    public function diagnosticsFor(Authenticatable $user): iterable
    {
        if (! $this->canViewLogs($user)) {
            return [];
        }

        $errors = $this->recorder->recent();

        if ($errors === []) {
            return [];
        }

        $occurrences = array_sum(array_column($errors, 'count'));
        $latest = end($errors);

        return [new StatusBarDiagnostic(
            id: 'system.reported-errors',
            severity: StatusVariant::Error,
            source: __('Errors'),
            summary: trans_choice(
                ':count application error in the last 24 hours|:count application errors in the last 24 hours',
                $occurrences,
                ['count' => $occurrences],
            ),
            detail: __('Latest: :message', ['message' => $latest['message'] !== '' ? $latest['message'] : $latest['exception']]),
            target: Route::has('admin.system.logs.index') ? route('admin.system.logs.index') : null,
            metadata: [
                'distinct' => count($errors),
                'latest_exception' => $latest['exception'],
            ],
        )];
    }

    private function canViewLogs(Authenticatable $user): bool
    {
        try {
            return $this->authorizationService
                ->can(Actor::forUser($user), 'admin.system.log.list')
                ->allowed;
        } catch (\Throwable) {
            return false;
        }
    }
}
