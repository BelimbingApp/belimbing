<?php

namespace App\Base\Tenancy\Console\Commands;

use App\Base\Tenancy\Services\TenantContextMissRecorder;
use Illuminate\Console\Command;

final class TenantMissesCommand extends Command
{
    protected $signature = 'blb:tenant:misses
        {--json : Emit JSON instead of a table}';

    protected $description = 'Show the last recorded tenant-context misses (route and resolver)';

    public function handle(TenantContextMissRecorder $recorder): int
    {
        $misses = $recorder->recent();

        if ($this->option('json')) {
            $this->line(json_encode($misses, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        }

        if ($misses === []) {
            $this->info('No tenant-context misses recorded.');

            return self::SUCCESS;
        }

        $this->table(
            ['At', 'Route', 'Resolver'],
            array_map(static fn (array $row): array => [
                $row['at'],
                $row['route'] ?? '(unnamed)',
                $row['resolver'],
            ], $misses),
        );

        return self::SUCCESS;
    }
}
