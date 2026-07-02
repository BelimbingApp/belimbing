<?php

namespace App\Base\Software\Console\Commands;

use App\Base\Software\Services\DeploymentService;
use App\Base\Software\Services\FrankenPhpDomainRuntimeReloader;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Throwable;

class DomainRuntimeReloadCommand extends Command
{
    protected $signature = 'blb:domain-runtime:reload
        {--delay=2 : Seconds to wait before reloading workers.}
        {--clear-runtime-caches : Clear runtime caches before reloading workers.}';

    protected $description = 'Reload FrankenPHP workers after a deferred runtime change.';

    public function handle(DeploymentService $deployment): int
    {
        $delay = max(0, min(30, (int) $this->option('delay')));

        if ($delay > 0) {
            sleep($delay);
        }

        try {
            foreach ($deployment->reload(clearRuntimeCaches: (bool) $this->option('clear-runtime-caches')) as $line) {
                $this->line($line);
            }

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        } finally {
            Cache::forget(FrankenPhpDomainRuntimeReloader::PENDING_CACHE_KEY);
        }
    }
}
