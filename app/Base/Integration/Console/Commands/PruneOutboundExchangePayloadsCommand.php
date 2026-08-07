<?php

namespace App\Base\Integration\Console\Commands;

use App\Base\Integration\Services\OutboundExchangePruner;
use Illuminate\Console\Command;

final class PruneOutboundExchangePayloadsCommand extends Command
{
    protected $signature = 'blb:integration:payloads:prune';

    protected $description = 'Redact outbound integration payloads whose retention period has elapsed';

    public function handle(OutboundExchangePruner $pruner): int
    {
        $count = $pruner->prunePayloads();
        $this->components->info("Redacted payloads from {$count} outbound exchange(s).");

        return self::SUCCESS;
    }
}
