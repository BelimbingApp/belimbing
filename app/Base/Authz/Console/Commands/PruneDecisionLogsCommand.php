<?php

namespace App\Base\Authz\Console\Commands;

use App\Base\Authz\Models\DecisionLog;
use Illuminate\Console\Command;

/**
 * Apply DecisionLog retention (#874).
 *
 * Platform maintenance: runs with no ambient tenant. Does not write an audit
 * action row for itself beyond whatever CommandListener already records.
 */
final class PruneDecisionLogsCommand extends Command
{
    protected $signature = 'blb:authz:decision-logs:prune
                            {--days= : Override authz.decision_log_retention_days}
                            {--dry-run : Count rows that would be deleted without deleting}';

    protected $description = 'Delete authz decision log rows older than the configured retention period';

    public function handle(): int
    {
        $days = $this->option('days');
        $days = $days !== null && $days !== ''
            ? max(1, (int) $days)
            : (int) config('authz.decision_log_retention_days', 90);
        $cutoff = now()->subDays($days);
        $query = DecisionLog::query()->where('occurred_at', '<', $cutoff);
        $count = (clone $query)->count();

        if ($this->option('dry-run')) {
            $this->components->info("Would delete {$count} decision log row(s) older than {$cutoff->toDateTimeString()} ({$days} days).");

            return self::SUCCESS;
        }

        $deleted = $query->delete();
        $this->components->info("Deleted {$deleted} decision log row(s) older than {$cutoff->toDateTimeString()} ({$days} days).");

        return self::SUCCESS;
    }
}
