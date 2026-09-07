<?php

namespace App\Base\Tenancy\Console\Commands;

use App\Base\Tenancy\Services\DomainCommandInventory;
use App\Base\Tenancy\Services\DomainCommandTenantAudit;
use Illuminate\Console\Command;

final class DomainCommandsCommand extends Command
{
    protected $signature = 'blb:domain-commands
        {--json : Emit JSON instead of a table}
        {--audit : Fail when Domain commands lack TenantScopedCommand without an allowlist reason}';

    protected $description = 'List Artisan commands registered by every mounted Domain module';

    public function handle(DomainCommandInventory $inventory, DomainCommandTenantAudit $audit): int
    {
        if ($this->option('audit')) {
            return $this->audit($audit);
        }

        $rows = $inventory->all();

        if ($this->option('json')) {
            $this->line(json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        }

        $this->table(
            ['Domain', 'Module', 'Name', 'Class', 'Tenant-scoped'],
            array_map(static fn (array $row): array => [
                $row['domain'],
                $row['module'],
                $row['name'],
                $row['class'],
                $row['tenant_scoped'] ? 'yes' : 'no',
            ], $rows),
        );

        return self::SUCCESS;
    }

    private function audit(DomainCommandTenantAudit $audit): int
    {
        $failures = $audit->failures();

        if ($this->option('json')) {
            $this->line(json_encode($failures, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

            return $failures === [] ? self::SUCCESS : self::FAILURE;
        }

        if ($failures === []) {
            $this->info('Domain command tenant-scope audit passed.');

            return self::SUCCESS;
        }

        $this->error(sprintf(
            '%d domain command(s) missing TenantScopedCommand:',
            count($failures),
        ));

        $this->table(
            ['Domain', 'Module', 'Name', 'Class', 'Missing'],
            array_map(static fn (array $row): array => [
                $row['domain'],
                $row['module'],
                $row['name'],
                $row['class'],
                implode(', ', $row['missing']),
            ], $failures),
        );

        return self::FAILURE;
    }
}
