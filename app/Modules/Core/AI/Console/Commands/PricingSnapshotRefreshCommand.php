<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\AI\Console\Commands;

use App\Modules\Core\AI\Services\Pricing\RefreshPricingSnapshot;
use Illuminate\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'blb:ai:pricing:refresh')]
class PricingSnapshotRefreshCommand extends Command
{
    protected $description = 'Refresh AI token pricing snapshots from configured sources';

    protected $signature = 'blb:ai:pricing:refresh
        {--url= : Override the LiteLLM snapshot URL for this run}';

    public function handle(RefreshPricingSnapshot $refresh): int
    {
        $result = $refresh->refresh($this->option('url') ?: null);

        $this->components->info($result['refreshed'] ? 'Pricing snapshot refreshed.' : 'Pricing snapshot refresh skipped; using fallback.');
        $this->components->twoColumnDetail('Source', (string) $result['source']);
        $this->components->twoColumnDetail('Snapshot Date', (string) ($result['snapshot_date'] ?? 'none'));
        $this->components->twoColumnDetail('Models', number_format((int) $result['model_count']));
        $this->components->twoColumnDetail('Rows', number_format((int) $result['row_count']));
        $this->components->twoColumnDetail('Imported', number_format((int) $result['imported_count']));
        $this->components->twoColumnDetail('Skipped', number_format((int) $result['skipped_count']));

        if (($result['error'] ?? null) !== null) {
            $this->components->warn((string) $result['error']);
        }

        return self::SUCCESS;
    }
}
