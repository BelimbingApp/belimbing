<?php

namespace App\Base\Software\Console\Commands;

use App\Base\Software\Services\DeploymentLogClassifier;
use App\Base\Software\Services\DeploymentRunHistory;
use App\Base\Software\Services\DeploymentService;
use App\Base\Software\Services\FrankenPhpDomainRuntimeReloader;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Throwable;

class DomainRuntimeReloadCommand extends Command
{
    protected $signature = 'blb:domain-runtime:reload
        {--delay=2 : Seconds to wait before reloading workers.}
        {--clear-runtime-caches : Clear runtime caches before reloading workers.}
        {--run-id= : Run record this reload should close when it finishes.}';

    protected $description = 'Reload FrankenPHP workers after a deferred runtime change.';

    public function handle(DeploymentService $deployment, DeploymentRunHistory $history): int
    {
        $delay = max(0, min(30, (int) $this->option('delay')));

        if ($delay > 0) {
            sleep($delay);
        }

        // The request that scheduled this reload could only record it as pending —
        // the work happens out here. Close that record with what actually happened,
        // otherwise the run box claims "in progress" long after the workers came
        // back, while the FrankenPHP card next to it already reports success.
        $runId = (string) $this->option('run-id');
        $history->rememberReloadRunning((string) __('Runtime reload is running.'));

        try {
            $log = $deployment->reload(clearRuntimeCaches: (bool) $this->option('clear-runtime-caches'));

            foreach ($log as $line) {
                $this->line($line);
            }

            // The reload's own last line is "Queue restart signaled.", which says
            // nothing about whether the workers came back. Close on a line that
            // does, since the run box shows it as the run's summary.
            $completion = $this->completionLine($log);
            $this->line($completion);
            $this->closeRun($history, $runId, $this->reloadStatus($log), [...$log, $completion]);

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $failure = (string) __('Warning: runtime reload failed before completion: :message', ['message' => $exception->getMessage()]);
            $history->rememberReload(false, $failure, '');
            $this->closeRun($history, $runId, 'error', [$failure]);
            $this->error($exception->getMessage());

            return self::FAILURE;
        } finally {
            Cache::forget(FrankenPhpDomainRuntimeReloader::PENDING_CACHE_KEY);
        }
    }

    /**
     * A reload triggered by a domain or extension change has no run box behind it,
     * so there is simply nothing to close — completeDeploymentRun() no-ops on an
     * unknown id, and an empty id means the caller never opened a record.
     *
     * @param  list<string>  $lines
     */
    private function closeRun(DeploymentRunHistory $history, string $runId, string $status, array $lines): void
    {
        if ($runId === '') {
            return;
        }

        $history->completeDeploymentRun($runId, $status, $lines);
    }

    /**
     * @param  list<string>  $log
     */
    private function reloadStatus(array $log): string
    {
        return match (true) {
            DeploymentLogClassifier::hasError($log) => 'error',
            DeploymentLogClassifier::hasWarning($log) => 'warning',
            default => 'success',
        };
    }

    /**
     * @param  list<string>  $log
     */
    private function completionLine(array $log): string
    {
        return match (true) {
            DeploymentLogClassifier::hasError($log) => (string) __('Runtime reload finished with errors; web workers may still be serving old code.'),
            DeploymentLogClassifier::hasWarning($log) => (string) __('Runtime reload finished with warnings; web workers may still be serving old code.'),
            DeploymentLogClassifier::hasNoRuntimeNotice($log) => (string) __('Runtime reload had nothing to reload; no web workers were running.'),
            default => (string) __('Runtime reload complete. Web workers are serving the current code.'),
        };
    }
}
