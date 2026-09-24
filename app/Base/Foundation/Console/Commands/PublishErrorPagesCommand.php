<?php

namespace App\Base\Foundation\Console\Commands;

use App\Base\Foundation\Services\ErrorPages;
use Illuminate\Console\Command;

final class PublishErrorPagesCommand extends Command
{
    protected $signature = 'blb:error-pages:publish
        {--check : Report stale pages without writing; exits 1 when any page is out of date}';

    protected $description = 'Render the static fallback error pages (Caddy and CDN) under public/errors from the Blade error shell';

    public function handle(ErrorPages $pages): int
    {
        if ($this->option('check')) {
            $stale = $pages->stale();

            if ($stale === []) {
                $this->info('Static error pages are up to date.');

                return self::SUCCESS;
            }

            foreach ($stale as $path) {
                $this->error("Stale: {$path}");
            }

            $this->line('Run `php artisan blb:error-pages:publish` and commit the result.');

            return self::FAILURE;
        }

        foreach ($pages->publish() as $path) {
            $this->info("Published {$path}");
        }

        return self::SUCCESS;
    }
}
