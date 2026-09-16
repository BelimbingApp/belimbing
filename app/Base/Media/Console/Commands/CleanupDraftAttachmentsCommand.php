<?php

namespace App\Base\Media\Console\Commands;

use App\Base\Media\Services\PrivateAttachmentStore;
use App\Base\Tenancy\Console\TenantScopedCommand;

final class CleanupDraftAttachmentsCommand extends TenantScopedCommand
{
    protected $signature = 'blb:media:attachments:cleanup {--hours=24 : Minimum draft age in hours}';

    protected $description = 'Delete abandoned private attachment drafts for one tenant';

    public function handle(PrivateAttachmentStore $attachments): int
    {
        $hours = filter_var($this->option('hours'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($hours === false) {
            $this->components->error('The --hours option must be a positive whole number.');

            return self::FAILURE;
        }

        $count = $attachments->cleanupDraftsBefore(now('UTC')->subHours($hours));
        $this->components->info("Removed {$count} abandoned attachment draft(s).");

        return self::SUCCESS;
    }
}
