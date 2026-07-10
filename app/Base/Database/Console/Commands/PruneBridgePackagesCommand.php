<?php

namespace App\Base\Database\Console\Commands;

use App\Base\Database\Services\Bridge\BridgePackageRetention;
use Illuminate\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'blb:db:bridge:prune')]
class PruneBridgePackagesCommand extends Command
{
    protected $signature = 'blb:db:bridge:prune
                            {--commit : Delete the listed package files}
                            {--include-unapplied : Also delete old unapplied/orphaned Incoming and Outgoing files}
                            {--json : Emit machine-readable JSON}';

    protected $description = 'Preview or apply conservative Data Bridge package retention';

    public function handle(BridgePackageRetention $retention): int
    {
        $result = $retention->prune(
            commit: (bool) $this->option('commit'),
            includeUnapplied: (bool) $this->option('include-unapplied'),
        );

        if ($this->option('json')) {
            $this->line(json_encode($result, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        }

        $this->components->info($result['commit']
            ? trans_choice(':count package file deleted.|:count package files deleted.', count($result['deleted']), ['count' => count($result['deleted'])])
            : trans_choice(':count package file is eligible; no files were deleted.|:count package files are eligible; no files were deleted.', count($result['candidates']), ['count' => count($result['candidates'])]));

        if ($result['candidates'] !== []) {
            $this->table(
                ['Category', 'Reason', 'Path'],
                array_map(fn (array $candidate): array => [
                    $candidate['category'],
                    $candidate['reason'],
                    $candidate['path'],
                ], $result['candidates']),
            );
        }

        if (! $this->option('include-unapplied')) {
            $this->components->twoColumnDetail(
                'Unapplied and Outgoing packages',
                'retained (use --include-unapplied only after explicit review)',
            );
        }

        return self::SUCCESS;
    }
}
