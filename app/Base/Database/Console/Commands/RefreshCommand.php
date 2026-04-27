<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Base\Database\Console\Commands;

use App\Base\Database\Concerns\GuardsGlobalReset;
use Illuminate\Console\Command;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Console\Migrations\RefreshCommand as IlluminateRefreshCommand;
use Illuminate\Database\Events\DatabaseRefreshed;
use Symfony\Component\Console\Input\InputOption;

class RefreshCommand extends IlluminateRefreshCommand
{
    use GuardsGlobalReset;

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Reset and re-run all migrations';

    /**
     * Execute the console command.
     *
     * Blocks global refresh unless --force-wipe is explicitly passed.
     */
    public function handle(): int
    {
        if ($result = $this->guardGlobalReset()) {
            return $result;
        }

        // Re-implement parent flow so we can pass --dev and --force-wipe through.
        if ($this->isProhibited() || ! $this->confirmToProceed()) {
            return Command::FAILURE;
        }

        $database = $this->input->getOption('database');
        $path = $this->input->getOption('path');
        $step = $this->input->getOption('step') ?: 0;

        if ($step > 0) {
            $this->call('migrate:rollback', array_filter([
                '--database' => $database,
                '--path' => $path,
                '--realpath' => $this->input->getOption('realpath'),
                '--step' => $step,
                '--force' => true,
            ]));
        } else {
            $this->call('migrate:reset', array_filter([
                '--database' => $database,
                '--path' => $path,
                '--realpath' => $this->input->getOption('realpath'),
                '--force' => true,
                '--force-wipe' => true,
            ]));
        }

        $this->call('migrate', array_filter([
            '--database' => $database,
            '--path' => $path,
            '--realpath' => $this->input->getOption('realpath'),
            '--force' => true,
            '--seed' => $this->needsSeeding(),
            '--seeder' => $this->option('seeder'),
            '--dev' => $this->option('dev'),
        ]));

        if ($this->laravel->bound(Dispatcher::class)) {
            $this->laravel[Dispatcher::class]->dispatch(
                new DatabaseRefreshed($database, $this->needsSeeding())
            );
        }

        return 0;
    }

    /**
     * Get the console command options.
     *
     * Adds --dev and --force-wipe to the parent options.
     *
     * {@inheritdoc}
     */
    protected function getOptions(): array
    {
        $options = parent::getOptions();

        $options[] = [
            'dev',
            null,
            InputOption::VALUE_NONE,
            'Run dev seeders after production seeders (APP_ENV=local only). Implies --seed.',
        ];

        $options[] = [
            'force-wipe',
            null,
            InputOption::VALUE_NONE,
            'Allow destructive global refresh (bypasses safety guard).',
        ];

        return $options;
    }
}
