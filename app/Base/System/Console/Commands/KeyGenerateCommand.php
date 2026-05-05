<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Base\System\Console\Commands;

use Illuminate\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * Blocks the raw `key:generate` command in BLB deployments.
 *
 * BLB overrides this command to prevent operators from silently stranding
 * backup DEKs. The `app-key` encryption mode wraps each backup's data
 * encryption key under a KEK derived from APP_KEY; changing APP_KEY without
 * re-wrapping those DEKs makes every existing backup irrecoverable.
 *
 * Use `blb:key:rotate` instead — it generates a fresh key, writes it to
 * .env, and re-wraps all backup DEKs atomically.
 */
#[AsCommand(name: 'key:generate')]
final class KeyGenerateCommand extends Command
{
    protected $signature = 'key:generate {--show} {--force}';

    protected $description = '[disabled in BLB — use blb:key:rotate]';

    public function handle(): int
    {
        // Allow key generation when APP_KEY is not yet set (fresh install / CI bootstrap).
        // The risk only exists when rotating an existing key: changing APP_KEY without
        // re-wrapping backup DEKs makes every app-key-mode backup irrecoverable.
        $current = (string) config('app.key', '');

        if ($current === '') {
            // No existing key — delegate to the real Laravel command via artisan PHP.
            // We can't call the parent because we've replaced the binding; run it as
            // a subprocess so it gets the real implementation.
            $force = $this->option('force') ? ' --force' : '';
            $show = $this->option('show') ? ' --show' : '';
            passthru(implode(' ', [PHP_BINARY, 'artisan', 'key:generate', '--ansi'.$force.$show]), $code);

            return (int) $code;
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
