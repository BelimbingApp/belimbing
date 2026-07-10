<?php

namespace App\Base\Database\Console\Commands;

use App\Base\Database\DTO\DevelopmentSanitizationResult;
use App\Base\Database\Exceptions\DevelopmentInstanceRequiredException;
use App\Base\Database\Exceptions\DevelopmentSanitizationException;
use App\Base\Database\Services\DevelopmentSanitizer;
use Illuminate\Console\Command;

class SanitizeDevelopmentDatabaseCommand extends Command
{
    protected $signature = 'blb:db:sanitize-dev
        {--commit : Disable external automation and remove credentials and sessions}';

    protected $description = 'Neutralize a restored production database for safe development use';

    public function handle(DevelopmentSanitizer $sanitizer): int
    {
        try {
            $preview = $sanitizer->preview();
        } catch (DevelopmentInstanceRequiredException|DevelopmentSanitizationException $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        $this->table(
            [__('State'), __('Rows/actions'), __('Sanitization')],
            array_map($this->resultRow(...), $preview),
        );

        if (! $this->option('commit')) {
            $this->components->warn(__('Preview only. Rerun with --commit immediately after restoring a production backup.'));

            return self::SUCCESS;
        }

        try {
            $results = $sanitizer->apply();
        } catch (DevelopmentInstanceRequiredException|DevelopmentSanitizationException $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        $this->components->info(__('Development sanitization applied.'));
        $this->table(
            [__('State'), __('Affected'), __('Sanitization')],
            array_map($this->resultRow(...), $results),
        );

        return self::SUCCESS;
    }

    /** @return list<int|string> */
    private function resultRow(DevelopmentSanitizationResult $result): array
    {
        return [$result->label, $result->affected, $result->detail];
    }
}
