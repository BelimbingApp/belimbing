<?php

namespace App\Base\Software\Livewire\Deployment;

use App\Base\Authz\Contracts\AuthorizationService;
use App\Base\Authz\DTO\Actor;
use App\Base\Software\Services\DeploymentRunHistory;
use App\Base\Software\Services\DeploymentService;
use App\Base\Software\Services\FrankenPhpDomainRuntimeReloader;
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
    #[Session('admin.system.software.updates.run_log')]
    public array $log = [];

    public bool $latestStatusLoaded = false;

    public function loadLatestStatus(): void
    {
        $this->latestStatusLoaded = true;
    }

    public function updateRepo(
        string $key,
        DeploymentService $deployment,
        DeploymentRunHistory $history,
        FrankenPhpDomainRuntimeReloader $runtimeReloader,
    ): void {
        $this->runAction($history, fn (): array => $this->appendRuntimeReloadSchedule(
            $deployment->update([$key], fn (string $line) => $this->streamRunLine($line), reloadWorkers: false),
            $runtimeReloader,
        ));
    }

    public function updateAll(
        DeploymentService $deployment,
        DeploymentRunHistory $history,
        FrankenPhpDomainRuntimeReloader $runtimeReloader,
    ): void {
        $this->runAction($history, fn (): array => $this->appendRuntimeReloadSchedule(
            $deployment->update([], fn (string $line) => $this->streamRunLine($line), reloadWorkers: false),
            $runtimeReloader,
        ));
    }

    public function reloadOnly(DeploymentRunHistory $history, FrankenPhpDomainRuntimeReloader $runtimeReloader): void
    {
        $this->runAction($history, fn (): array => $this->appendRuntimeReloadSchedule([], $runtimeReloader));
    }

    public function rebuildPhp(
        DeploymentService $deployment,
        DeploymentRunHistory $history,
        FrankenPhpDomainRuntimeReloader $runtimeReloader,
    ): void {
        $this->runAction($history, fn (): array => $this->appendRuntimeReloadSchedule(
            $deployment->rebuildPhp(reloadWorkers: false),
            $runtimeReloader,
        ));
    }

    public function rebuildAssets(DeploymentService $deployment, DeploymentRunHistory $history): void
    {
        $this->runAction($history, fn (): array => $deployment->rebuildAssets());
    }

    /**
     * Authorize, reset the live log, run the work, then record a durable last-run so
     * the run box (and its time) survive a page reload or a fresh session.
     *
     * @param  callable(): list<string>  $work
     */
    private function runAction(DeploymentRunHistory $history, callable $work): void
    {
        $this->authorizeManage();
        set_time_limit(0);
        $this->startRunLog();
        $this->log = $work();
        $outcome = $this->runOutcome();
        $history->rememberDeploymentRun($this->log, $outcome);
        $this->streamRunRecordedMarker($outcome);
        $this->dispatch('run-finished', status: $outcome, refresh: true);
    }

    public function render(DeploymentService $deployment, DeploymentRunHistory $history): View
    {
        $status = $this->latestStatusLoaded
            ? $deployment->status()
            : $deployment->localStatus();

        // The run box shows this session's live log while one is running/just ran,
        // and otherwise falls back to the durable last-run record so its outcome and
        // time are still there on a fresh visit (or after an interrupted run).
        $lastRun = $history->lastDeploymentRun();
        $hasSessionLog = $this->log !== [];
        $runStatus = $hasSessionLog ? $this->runOutcome() : ($lastRun['status'] ?? 'idle');
        $displayLog = $hasSessionLog ? $this->log : ($lastRun['log'] ?? []);

        return view('livewire.admin.system.software.deployment.index', [
            'status' => $status,
            'behind' => collect($status)->contains(fn (array $s): bool => $s['up_to_date'] === false),
            'latestStatusLoaded' => $this->latestStatusLoaded,
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
            'lastReload' => $history->lastReload(),
            'packageManager' => $deployment->frontendPackageManager(),
            'lastComposerRun' => $history->lastComposerRun(),
            'lastFrontendRun' => $history->lastFrontendRun(),
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
            'admin.system.software.updates.manage',
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

    private function streamRunRecordedMarker(string $outcome): void
    {
        $this->stream(
            '<span class="hidden" aria-hidden="true" data-deployment-run-recorded="true" data-run-outcome="'.e($outcome).'"></span>',
            to: 'runLog',
        );
    }

    /**
     * @param  list<string>  $log
     * @return list<string>
     */
    private function appendRuntimeReloadSchedule(array $log, FrankenPhpDomainRuntimeReloader $runtimeReloader): array
    {
        if ($this->logOutcome($log) === 'error') {
            return $log;
        }

        foreach ($runtimeReloader->reloadAfterSoftwareUpdate() as $line) {
            $log[] = $line;
            $this->streamRunLine($line);
        }

        return $log;
    }

    private function runOutcome(): string
    {
        return $this->logOutcome($this->log);
    }

    /**
     * @param  list<string>  $log
     */
    private function logOutcome(array $log): string
    {
        return match (true) {
            $log === [] => 'idle',
            collect($log)->contains(fn (string $line): bool => $this->isErrorLine($line)) => 'error',
            collect($log)->contains(fn (string $line): bool => $this->isWarningLine($line)) => 'warning',
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
