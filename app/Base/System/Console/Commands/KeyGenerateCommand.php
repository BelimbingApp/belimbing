<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Base\System\Console\Commands;

use Illuminate\Foundation\Console\KeyGenerateCommand as LaravelKeyGenerateCommand;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * Blocks `key:generate` once APP_KEY is set, in BLB deployments.
 *
 * BLB's `app-key` backup encryption mode wraps each backup's data encryption
 * key (DEK) under a KEK derived from APP_KEY; rotating APP_KEY without
 * re-wrapping those DEKs makes every existing `app-key`-mode backup
 * irrecoverable. Operators must use `blb:key:rotate` instead, which
 * regenerates the key and re-wraps all manifest DEKs in one step.
 *
 * Fresh-install bootstrap (APP_KEY empty in .env) still works: this command
 * delegates to Laravel's stock implementation by calling parent::handle().
 */
#[AsCommand(name: 'key:generate')]
final class KeyGenerateCommand extends LaravelKeyGenerateCommand
{
    protected $description = '[blocked once APP_KEY is set in BLB — use blb:key:rotate]';

    public function handle(): int
    {
        // Allow key generation when APP_KEY is not yet set (fresh install / CI bootstrap).
        // The risk only exists when rotating an existing key: changing APP_KEY without
        // re-wrapping backup DEKs makes every app-key-mode backup irrecoverable.
        if ((string) config('app.key', '') === '') {
            parent::handle();

            return self::SUCCESS;
        }

        $this->components->error('key:generate is disabled in BLB when APP_KEY is already set.');
        $this->components->warn(
            'Running key:generate directly would strand all existing backup DEKs, '.
            'making every app-key-mode backup irrecoverable.'
        );
        $this->newLine();
        $this->line('  Use instead: <comment>php artisan blb:key:rotate</comment>');
        $this->newLine();

        return self::FAILURE;
    }
}
