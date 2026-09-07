<?php

namespace App\Base\FeatureFlags\Console\Commands;

use App\Base\FeatureFlags\Services\FeatureFlags;
use App\Base\Tenancy\Contracts\TenantContext;
use Illuminate\Console\Command;

/**
 * List every declared feature flag and orphaned overrides for the current tenant.
 */
final class ListFeatureFlagsCommand extends Command
{
    protected $signature = 'blb:feature-flags
                            {--json : Emit JSON instead of a table}';

    protected $description = 'List module-declared feature flags for the current tenant';

    public function handle(FeatureFlags $flags, TenantContext $tenants): int
    {
        if ($tenants->currentTenantId() === null) {
            $this->error('No tenant is in context. Set a tenant before listing feature flags.');

            return self::FAILURE;
        }

        $rows = $flags->listForCurrentTenant();

        if ($this->option('json')) {
            $this->line(json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        if ($rows === []) {
            $this->info('No feature flags are declared in enabled module descriptors.');

            return self::SUCCESS;
        }

        $this->table(
            ['Flag', 'Module', 'Default', 'Enabled', 'Overridden', 'Orphaned', 'Description'],
            array_map(static fn (array $row): array => [
                $row['flag'],
                $row['module'] ?? '—',
                $row['default'] === null ? '—' : ($row['default'] ? 'true' : 'false'),
                $row['enabled'] ? 'true' : 'false',
                $row['overridden'] ? 'yes' : 'no',
                $row['orphaned'] ? 'yes' : 'no',
                $row['description'],
            ], $rows),
        );

        return self::SUCCESS;
    }
}
