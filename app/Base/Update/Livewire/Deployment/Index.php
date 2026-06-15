<?php

namespace App\Base\Update\Livewire\Deployment;

use App\Base\Authz\Contracts\AuthorizationService;
use App\Base\Authz\DTO\Actor;
use App\Base\Update\Services\DeploymentService;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Session;
use Livewire\Component;

/**
 * Shows each Distribution Bundle's current vs latest commit and lets the operator update
 * per bundle (or all) and gracefully reload — the in-app counterpart to the host
 * deploy. The actual pull/refresh/migrate/reload lives in DeploymentService so it
 * stays cross-platform and testable.
 */
class Index extends Component
{
    /** @var list<string> last action's log lines (persists so the resting panel survives a page visit) */
    #[Session('admin.system.update.deployment.run_log')]
    public array $log = [];

    public function updateRepo(string $key, DeploymentService $deployment): void
    {
        $this->runAction($deployment, fn (): array => $deployment->update([$key], fn (string $line) => $this->streamRunLine($line)));
    }

    public function updateAll(DeploymentService $deployment): void
    {
        $this->runAction($deployment, fn (): array => $deployment->update([], fn (string $line) => $this->streamRunLine($line)));
    }

    public function reloadOnly(DeploymentService $deployment): void
    {
        $this->runAction($deployment, fn (): array => $deployment->reload());
    }

    public function rebuildPhp(DeploymentService $deployment): void
    {
        $this->runAction($deployment, fn (): array => $deployment->rebuildPhp());
    }

    public function rebuildAssets(DeploymentService $deployment): void
    {
        $this->runAction($deployment, fn (): array => $deployment->rebuildAssets());
    }

    /**
     * Authorize, reset the live log, run the work, then record a durable last-run so
     * the run box (and its time) survive a page reload or a fresh session.
     *
     * @param  callable(): list<string>  $work
     */
    private function runAction(DeploymentService $deployment, callable $work): void
    {
        $this->authorizeManage();
        $this->startRunLog();
        $this->log = $work();
        $deployment->rememberDeploymentRun($this->log, $this->runOutcome());
        $this->dispatch('run-finished');
    }

    public function render(DeploymentService $deployment): View
    {
        $status = $deployment->status();

        // The run box shows this session's live log while one is running/just ran,
        // and otherwise falls back to the durable last-run record so its outcome and
        // time are still there on a fresh visit (or after an interrupted run).
        $lastRun = $deployment->lastDeploymentRun();
        $hasSessionLog = $this->log !== [];
        $runStatus = $hasSessionLog ? $this->runOutcome() : ($lastRun['status'] ?? 'idle');
        $displayLog = $hasSessionLog ? $this->log : ($lastRun['log'] ?? []);

        return view('livewire.admin.system.update.deployment.index', [
            'status' => $status,
            'behind' => collect($status)->contains(fn (array $s): bool => $s['up_to_date'] === false),
            'checkFailures' => collect($status)
                ->filter(fn (array $s): bool => $s['latest'] === null && $s['error'] !== null)
                ->map(fn (array $s): string => $s['repo'] ?? $s['path'])
                ->values()
                ->all(),
            'maintenanceActive' => app()->isDownForMaintenance(),
            'runStatus' => $runStatus,
            'runLabel' => $this->statusLabel($runStatus),
            'runVariant' => $this->statusVariant($runStatus),
            'runSummary' => $hasSessionLog ? $this->runSummary() : ($lastRun['summary'] ?? ''),
            'runAt' => $lastRun['attempted_at'] ?? null,
            'displayLog' => $displayLog,
            'lastReload' => $deployment->lastReload(),
            'packageManager' => $deployment->frontendPackageManager(),
            'lastComposerRun' => $deployment->lastComposerRun(),
            'lastFrontendRun' => $deployment->lastFrontendRun(),
        ]);
    }

    public function runLineClass(string $line): string
    {
        return match (true) {
            $this->isErrorLine($line) => 'text-status-danger',
            $this->isWarningLine($line) => 'text-status-warning',
            str_starts_with($line, 'Update complete.') || str_starts_with($line, 'Verified:') => 'text-status-success',
            default => '',
        };
    }

    private function authorizeManage(): void
    {
        app(AuthorizationService::class)->authorize(
            Actor::forUser(Auth::user()),
            'admin.system.update.deployment.manage',
        );
    }

    private function startRunLog(): void
    {
        $this->log = [];
        $this->stream('', replace: true, to: 'runLog');
    }

    private function streamRunLine(string $line): void
    {
        $class = $this->runLineClass($line);
        $classAttribute = $class !== '' ? ' class="'.$class.'"' : '';

        $this->stream('<div'.$classAttribute.'>'.e($line).'</div>', to: 'runLog');
    }

    private function runOutcome(): string
    {
        return match (true) {
            $this->log === [] => 'idle',
            collect($this->log)->contains(fn (string $line): bool => $this->isErrorLine($line)) => 'error',
            collect($this->log)->contains(fn (string $line): bool => $this->isWarningLine($line)) => 'warning',
            default => 'success',
        };
    }

    private function statusLabel(string $status): string
    {
        return match ($status) {
            'error' => (string) __('Needs action'),
            'warning' => (string) __('Warnings'),
            'success' => (string) __('Complete'),
            default => (string) __('No run yet'),
        };
    }

    private function statusVariant(string $status): string
    {
        return match ($status) {
            'error' => 'danger',
            'warning' => 'warning',
            'success' => 'success',
            default => 'default',
        };
    }

    private function runSummary(): string
    {
        $lastKey = array_key_last($this->log);

        return $lastKey !== null
            ? (string) $this->log[$lastKey]
            : (string) __('No update has run in this session.');
    }

    private function isErrorLine(string $line): bool
    {
        $lower = strtolower($line);

        return str_starts_with($line, 'FAILED:')
            || str_contains($lower, 'finished with errors')
            || str_contains($lower, ' install failed:')
            || str_contains($lower, ' build failed:')
            || str_contains($lower, ' refresh failed:');
    }

    private function isWarningLine(string $line): bool
    {
        return str_starts_with($line, 'Warning:')
            || str_starts_with($line, 'Still behind:')
            || str_starts_with($line, 'Could not verify')
            || str_contains(strtolower($line), 'finished with warnings');
    }
}
