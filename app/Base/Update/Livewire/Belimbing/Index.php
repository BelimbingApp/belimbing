<?php

namespace App\Base\Update\Livewire\Belimbing;

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
    #[Session('admin.system.update.belimbing.run_log')]
    public array $log = [];

    public function updateRepo(string $key, DeploymentService $deployment): void
    {
        $this->authorizeManage();
        $this->startRunLog();
        $this->log = $deployment->update([$key], fn (string $line) => $this->streamRunLine($line));
        $this->dispatch('run-finished');
    }

    public function updateAll(DeploymentService $deployment): void
    {
        $this->authorizeManage();
        $this->startRunLog();
        $this->log = $deployment->update([], fn (string $line) => $this->streamRunLine($line));
        $this->dispatch('run-finished');
    }

    public function reloadOnly(DeploymentService $deployment): void
    {
        $this->authorizeManage();
        $this->startRunLog();
        $this->log = $deployment->reload();
        $this->dispatch('run-finished');
    }

    public function rebuildPhp(DeploymentService $deployment): void
    {
        $this->authorizeManage();
        $this->startRunLog();
        $this->log = $deployment->rebuildPhp();
        $this->dispatch('run-finished');
    }

    public function rebuildAssets(DeploymentService $deployment): void
    {
        $this->authorizeManage();
        $this->startRunLog();
        $this->log = $deployment->rebuildAssets();
        $this->dispatch('run-finished');
    }

    public function render(DeploymentService $deployment): View
    {
        $status = $deployment->status();

        return view('livewire.admin.system.update.belimbing.index', [
            'status' => $status,
            'behind' => collect($status)->contains(fn (array $s): bool => $s['up_to_date'] === false),
            'checkFailures' => collect($status)
                ->filter(fn (array $s): bool => $s['latest'] === null && $s['error'] !== null)
                ->map(fn (array $s): string => $s['repo'] ?? $s['path'])
                ->values()
                ->all(),
            'runOutcome' => $this->runOutcome(),
            'runOutcomeLabel' => $this->runOutcomeLabel(),
            'runOutcomeVariant' => $this->runOutcomeVariant(),
            'runSummary' => $this->runSummary(),
            'lastReload' => $deployment->lastReload(),
            'packageManager' => $deployment->frontendPackageManager(),
            'lastComposerRun' => $deployment->lastComposerRun(),
            'lastFrontendRun' => $deployment->lastFrontendRun(),
        ]);
    }

    public function runLineClass(string $line): string
    {
        if ($this->isErrorLine($line)) {
            return 'text-status-danger';
        }

        if ($this->isWarningLine($line)) {
            return 'text-status-warning';
        }

        if (str_starts_with($line, 'Update complete.') || str_starts_with($line, 'Verified:')) {
            return 'text-status-success';
        }

        return '';
    }

    private function authorizeManage(): void
    {
        app(AuthorizationService::class)->authorize(
            Actor::forUser(Auth::user()),
            'admin.system.update.belimbing.manage',
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
        if ($this->log === []) {
            return 'idle';
        }

        if (collect($this->log)->contains(fn (string $line): bool => $this->isErrorLine($line))) {
            return 'error';
        }

        if (collect($this->log)->contains(fn (string $line): bool => $this->isWarningLine($line))) {
            return 'warning';
        }

        return 'success';
    }

    private function runOutcomeLabel(): string
    {
        return match ($this->runOutcome()) {
            'error' => (string) __('Needs action'),
            'warning' => (string) __('Warnings'),
            'success' => (string) __('Complete'),
            default => (string) __('No run yet'),
        };
    }

    private function runOutcomeVariant(): string
    {
        return match ($this->runOutcome()) {
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
