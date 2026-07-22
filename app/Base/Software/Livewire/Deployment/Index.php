<?php

namespace App\Base\Software\Livewire\Deployment;

use App\Base\Authz\Contracts\AuthorizationService;
use App\Base\Authz\DTO\Actor;
use App\Base\Software\Livewire\Deployment\Concerns\FormatsDeploymentRunOutput;
use App\Base\Software\Services\DeploymentRunHistory;
use App\Base\Software\Services\DeploymentService;
use App\Base\Software\Services\FrankenPhpDomainRuntimeReloader;
use App\Base\Software\Services\PhpExtensionDriftProbe;
use App\Base\Software\Services\SoftwareUpdateLauncher;
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
    use FormatsDeploymentRunOutput;

    public function placeholder(): View
    {
        // Outside the livewire. view namespace on purpose: component-name
        // discovery keys off the first view('livewire.*') string in the file
        // (see ComponentDiscoveryService), which must stay the render() view.
        return view('placeholders.page');
    }

    /** @var list<string> last action's log lines (persists so the resting panel survives a page visit) */
    #[Session('admin.system.software.updates.run_log')]
    public array $log = [];

    public bool $latestStatusLoaded = false;

    // Synced to the browser via Livewire's $wire proxy so Alpine can react to
    // it (x-bind:disabled depends on $wire.behind). @js(! $behind) in x-data
    // is a one-time snapshot that Livewire morph never re-evaluates, leaving
    // "Update all" stuck disabled after wire:init loads remote status.
    public bool $behind = false;

    public function loadLatestStatus(): void
    {
        $this->latestStatusLoaded = true;
    }

    public function updateRepo(
        string $key,
        DeploymentRunHistory $history,
        SoftwareUpdateLauncher $launcher,
    ): void {
        $this->runDetachedUpdate($history, $launcher, [$key]);
    }

    public function updateAll(
        DeploymentRunHistory $history,
        SoftwareUpdateLauncher $launcher,
    ): void {
        $this->runDetachedUpdate($history, $launcher, []);
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
            $deployment->rebuildPhp(),
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
        $lock = app(SoftwareUpdateLauncher::class)->maintenanceActionLock();

        if (! $lock->get()) {
            $this->startRunLog();
            $this->streamRunLine((string) __('Warning: maintenance actions are unavailable while a software update is running.'));
            $this->log = [];
            $this->dispatch('run-finished', status: 'warning', refresh: false);

            return;
        }

        try {
            set_time_limit(0);
            $this->startRunLog();
            $this->log = $work();
            $outcome = $this->runOutcome();
            $history->rememberDeploymentRun($this->log, $outcome);
            $this->streamRunRecordedMarker($outcome);
            $this->dispatch('run-finished', status: $outcome, refresh: $outcome !== 'pending');
        } finally {
            $lock->release();
        }
    }

    /** @param list<string> $keys */
    private function runDetachedUpdate(
        DeploymentRunHistory $history,
        SoftwareUpdateLauncher $launcher,
        array $keys,
    ): void {
        $this->authorizeManage();
        $this->startRunLog();
        $lines = $launcher->launch($keys);

        foreach ($lines as $line) {
            $this->streamRunLine($line);
        }

        $outcome = $this->logOutcome($lines);

        if ($outcome === 'error') {
            $history->rememberDeploymentRun($lines, $outcome);
        }

        $this->log = [];
        $this->dispatch('run-finished', status: $outcome, refresh: false);

        // The run now lives in a detached process; hand the browser over to
        // the progress poller so this session watches it live instead of
        // sitting frozen on the launch line. (Dispatched after run-finished
        // so finishRun's running=false doesn't clobber the poller's state.)
        if ($outcome === 'pending') {
            $this->dispatch('follow-update-progress');
        }
    }

    public function render(
        DeploymentService $deployment,
        DeploymentRunHistory $history,
        SoftwareUpdateLauncher $launcher,
        PhpExtensionDriftProbe $extensionDrift,
    ): View {
        $status = $this->latestStatusLoaded
            ? $deployment->status()
            : $deployment->localStatus();

        $this->behind = collect($status)->contains(fn (array $s): bool => $s['up_to_date'] === false);

        // The run box shows this session's live log while one is running/just ran,
        // and otherwise falls back to the durable last-run record so its outcome and
        // time are still there on a fresh visit (or after an interrupted run).
        $lastRun = $history->lastDeploymentRun();
        $reloadState = $history->reloadState();
        $reloadStateStatus = $reloadState['status'] ?? null;
        $reloadStatePending = in_array($reloadStateStatus, ['pending', 'running'], true);
        $reloadStateStalled = $reloadStatePending && $history->reloadStateIsStale($reloadState);
        $reloadInProgress = $reloadStatePending && ! $reloadStateStalled;
        $hasSessionLog = $this->log !== [];
        $runStatus = $hasSessionLog ? $this->runOutcome() : ($lastRun['status'] ?? 'idle');
        $displayLog = $hasSessionLog ? $this->log : ($lastRun['log'] ?? []);
        $lastLogKey = array_key_last($this->log);
        $sessionRunSummary = $lastLogKey !== null
            ? (string) $this->log[$lastLogKey]
            : (string) __('No update has run in this session.');

        return view('livewire.admin.system.software.deployment.index', [
            'status' => $status,
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
            'runSummary' => $hasSessionLog ? $sessionRunSummary : ($lastRun['summary'] ?? ''),
            'runAt' => $lastRun['attempted_at'] ?? null,
            'displayLog' => $displayLog,
            'lastReload' => $history->lastReload(),
            'reloadState' => $reloadState,
            'reloadStateStatus' => $reloadStateStatus,
            'reloadStateStalled' => $reloadStateStalled,
            'reloadInProgress' => $reloadInProgress,
            'packageManager' => $deployment->frontendPackageManager(),
            'lastComposerRun' => $history->lastComposerRun(),
            'lastFrontendRun' => $history->lastFrontendRun(),
            'updateInProgress' => $launcher->inProgress(),
            'missingExtensions' => $extensionDrift->missingExtensions(),
        ]);
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
            collect($log)->contains(fn (string $line): bool => $this->isPendingLine($line)) => 'pending',
            collect($log)->contains(fn (string $line): bool => $this->isWarningLine($line)) => 'warning',
            default => 'success',
        };
    }
}
