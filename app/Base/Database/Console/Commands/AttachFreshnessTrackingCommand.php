<?php

namespace App\Base\Database\Console\Commands;

use App\Base\Database\Services\DataShare\Freshness\DataFreshnessAttachmentService;
use Illuminate\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * Phase 4: attach proven freshness tracking to eligible Local PostgreSQL tables.
 * Run explicitly after the Phase 3 go/no-go — never wired into migrate. On any
 * non-PostgreSQL driver it attaches nothing and reports so.
 */
#[AsCommand(name: 'blb:db:share:freshness-attach')]
class AttachFreshnessTrackingCommand extends Command
{
    protected $signature = 'blb:db:share:freshness-attach';

    protected $description = 'Attach freshness tracking triggers to eligible Local PostgreSQL tables (no-op on other drivers).';

    public function handle(DataFreshnessAttachmentService $service): int
    {
        $result = $service->attachEligible();

        if (! $result['driver_supported']) {
            $this->components->info('Freshness tracking is only supported on PostgreSQL; nothing attached.');

            return self::SUCCESS;
        }

        $this->components->info(sprintf(
            'Attached freshness tracking to %d eligible Local table(s).',
            count($result['attached']),
        ));

        return self::SUCCESS;
    }
}
