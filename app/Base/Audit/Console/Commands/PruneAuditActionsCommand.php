<?php

namespace App\Base\Audit\Console\Commands;

use App\Base\Audit\Models\AuditAction;
use Illuminate\Console\Command;

/**
 * Apply AuditAction retention (#874).
 *
 * Platform maintenance: runs with no ambient tenant. Rows with is_retained
 * stay. Listed in audit.exclude_commands so CommandListener does not write a
 * console.command row that this same prune would later delete.
 */
final class PruneAuditActionsCommand extends Command
{
    protected $signature = 'blb:audit:actions:prune
                            {--days= : Override audit.action_retention_days}
                            {--dry-run : Count rows that would be deleted without deleting}';

    protected $description = 'Delete audit action rows older than the configured retention period (retained rows survive)';

    public function handle(): int
    {
        $days = $this->option('days');
        $days = $days !== null && $days !== ''
            ? max(1, (int) $days)
            : (int) config('audit.action_retention_days', 90);
        $cutoff = now()->subDays($days);
        $query = AuditAction::query()
            ->where('occurred_at', '<', $cutoff)
            ->where('is_retained', false);
        $count = (clone $query)->count();

        if ($this->option('dry-run')) {
            $this->components->info("Would delete {$count} audit action row(s) older than {$cutoff->toDateTimeString()} ({$days} days).");

            return self::SUCCESS;
        }

        $deleted = $query->delete();
        $this->components->info("Deleted {$deleted} audit action row(s) older than {$cutoff->toDateTimeString()} ({$days} days).");

        return self::SUCCESS;
    }
}
