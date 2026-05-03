<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Base\Database\Console\Commands;

use App\Base\Database\Exceptions\BackupException;
use App\Base\Database\Services\Backup\BackupService;
use Illuminate\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;
use Throwable;

/**
 * Restore an encrypted backup into a non-current target database.
 *
 * For Postgres, --target is a database name on the active connection's
 * server. For SQLite, --target is a file path. The command refuses to write
 * into the configured application database unless backup.restore.allow_current_database
 * is explicitly true.
 */
#[AsCommand(name: 'blb:db:restore')]
final class RestoreCommand extends Command
{
    protected $signature = 'blb:db:restore
                            {--backup= : Backup ID to restore (matches the manifest filename)}
                            {--target= : Target database name (Postgres) or file path (SQLite). Must not be the current application database.}
                            {--local : Read from the configured local override disk instead of the default backup disk}';

    protected $description = 'Restore an encrypted database backup into a non-current target database';

    public function handle(BackupService $service): int
    {
        $config = (array) config('backup', []);

        if (($config['enabled'] ?? true) === false) {
            $this->components->info('Backup is disabled. There is nothing for blb:db:restore to do.');

            return self::SUCCESS;
        }

        $backupId = (string) $this->option('backup');
        $target = (string) $this->option('target');

        if ($backupId === '' || $target === '') {
            $this->components->error('Both --backup and --target are required.');

            return self::FAILURE;
        }

        $diskName = $this->option('local')
            ? (string) ($config['local_disk'] ?? 'local')
            : (string) ($config['disk'] ?? 'local');

        $writer = $service->resolveWriter($config);

        if ($writer->isCurrentDatabase($target)) {
            $allow = (bool) ($config['restore']['allow_current_database'] ?? false);
            if (! $allow) {
                $this->components->error('Refusing to restore over the current application database. Reconfigure DB_DATABASE or set backup.restore.allow_current_database=true to override.');

                return self::FAILURE;
            }
        }

        try {
            $staged = $service->stageDecryptedDump($config, $diskName, $backupId);
        } catch (BackupException $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        } catch (Throwable $e) {
            $this->components->error('Restore failed: '.$e->getMessage());

            return self::FAILURE;
        }

        try {
            // Manifest's driver must match the active driver for restore to make sense.
            if ($staged->manifest->driver !== $writer->driver()) {
                throw BackupException::restoreRefused(sprintf(
                    'Backup driver mismatch: artifact is %s but current connection is %s',
                    $staged->manifest->driver,
                    $writer->driver(),
                ));
            }

            $writer->restore($staged->plainPath, $target);
        } catch (BackupException $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        } catch (Throwable $e) {
            $this->components->error('Restore failed: '.$e->getMessage());

            return self::FAILURE;
        } finally {
            @unlink($staged->plainPath);
        }

        $this->components->info('Restore complete.');
        $this->components->twoColumnDetail('Backup ID', $staged->manifest->backupId);
        $this->components->twoColumnDetail('Driver', $staged->manifest->driver);
        $this->components->twoColumnDetail('Target', $target);
        $this->line('');
        $this->line('  Run smoke checks against the target before promoting it:');
        $this->line('    - <comment>php artisan migrate:status</comment>');
        $this->line('    - critical table row counts');
        $this->line('    - framework primitives (e.g. authz roles, base seeds)');

        return self::SUCCESS;
    }
}
