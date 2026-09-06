<?php

namespace App\Base\Routing\Console\Commands;

use App\Base\Routing\DomainRouteInventory;
use App\Base\Routing\DomainRouteMiddlewareAudit;
use Illuminate\Console\Command;

final class DomainRoutesCommand extends Command
{
    protected $signature = 'blb:domain-routes
        {--json : Emit JSON instead of a table}
        {--audit : Fail when required domain routes lack tenant or authorization middleware}';

    protected $description = 'List routes registered by every mounted Domain module';

    public function handle(DomainRouteInventory $inventory, DomainRouteMiddlewareAudit $audit): int
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
            ['Domain', 'Module', 'Methods', 'URI', 'Name', 'Middleware'],
            array_map(static fn (array $row): array => [
                $row['domain'],
                $row['module'],
                implode('|', $row['methods']),
                $row['uri'],
                $row['name'] ?? '',
                implode(', ', $row['middleware']),
            ], $rows),
        );

        return self::SUCCESS;
    }

    private function audit(DomainRouteMiddlewareAudit $audit): int
    {
        $failures = $audit->failures();

        if ($this->option('json')) {
            $this->line(json_encode($failures, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

            return $failures === [] ? self::SUCCESS : self::FAILURE;
        }

        if ($failures === []) {
            $this->info('Domain route middleware audit passed.');

            return self::SUCCESS;
        }

        $this->error(sprintf(
            '%d domain route(s) missing tenant assertion and/or authorization middleware:',
            count($failures),
        ));

        $this->table(
            ['Domain', 'Module', 'Methods', 'URI', 'Name', 'Missing', 'Middleware'],
            array_map(static fn (array $row): array => [
                $row['domain'],
                $row['module'],
                implode('|', $row['methods']),
                $row['uri'],
                $row['name'] ?? '',
                implode(', ', $row['missing']),
                implode(', ', $row['middleware']),
            ], $failures),
        );

        return self::FAILURE;
    }
}
