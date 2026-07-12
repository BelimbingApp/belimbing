<?php

namespace App\Base\Software\Console\Commands;

use App\Base\Software\Services\SoftwareInventoryStatusDiagnosticProvider;
use Illuminate\Console\Command;

class WarmInventorySnapshotCommand extends Command
{
    protected $signature = 'blb:software:inventory:warm';

    protected $description = 'Refresh the cached software inventory snapshot so no web request pays the git scan synchronously';

    public function handle(SoftwareInventoryStatusDiagnosticProvider $provider): int
    {
        $started = hrtime(true);

        $provider->warmInventorySnapshot();

        $this->info(sprintf('Inventory snapshot warmed in %.1f s.', (hrtime(true) - $started) / 1e9));

        return self::SUCCESS;
    }
}
