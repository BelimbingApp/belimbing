<?php

namespace App\Base\Routing\Console\Commands;

use App\Base\Routing\DomainRouteInventory;
use Illuminate\Console\Command;

final class DomainRoutesCommand extends Command
{
    protected $signature = 'blb:domain-routes {--json : Emit JSON instead of a table}';

    protected $description = 'List routes registered by every mounted Domain module';

    public function handle(DomainRouteInventory $inventory): int
    {
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
}
