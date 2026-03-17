<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Base\Database\Console\Commands;

use App\Base\Database\Concerns\GuardsGlobalReset;
use App\Base\Database\Concerns\InteractsWithModuleMigrations;
use Illuminate\Database\Console\Migrations\ResetCommand as IlluminateResetCommand;
use Symfony\Component\Console\Input\InputOption;

class ResetCommand extends IlluminateResetCommand
{
    use GuardsGlobalReset;
    use InteractsWithModuleMigrations;

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Rollback all database migrations';

    /**
     * Execute the console command.
     *
     * Loads all module migrations before resetting. Blocks unscoped reset
     * unless --force-wipe is explicitly passed.
     */
    public function handle(): int
    {
        if ($result = $this->guardGlobalReset()) {
            return $result;
        }

        $this->loadAllModuleMigrations();

        return (int) parent::handle();
    }

    /**
     * Get the console command options.
     *
     * Adds --force-wipe to the parent options.
     *
     * {@inheritdoc}
     */
    protected function getOptions(): array
    {
        $options = parent::getOptions();

        $options[] = [
            'force-wipe',
            null,
            InputOption::VALUE_NONE,
            'Allow destructive global reset (bypasses safety guard).',
        ];

        return $options;
    }
}
