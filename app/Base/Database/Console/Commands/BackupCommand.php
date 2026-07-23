<?php

namespace App\Base\Database\Console\Commands;

use App\Base\Database\Exceptions\BackupException;
use App\Base\Database\Services\Backup\BackupRuntimeSettings;
use App\Base\Database\Services\Backup\BackupService;
use App\Base\Database\Services\Backup\RetentionPolicy;
use Illuminate\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;
use Throwable;

/**
 * Create an encrypted database backup.
 *
 * Pipeline: dump (driver-aware) → encrypt (mode-aware) → upload to disk →
 * write sidecar manifest. Failure leaves no partial artifact behind.
 */
#[AsCommand(name: 'blb:db:backup')]
final class BackupCommand extends Command
{
    protected $signature = 'blb:db:backup
                            {--dry-run : Validate driver tooling, encryption config, and disk write access; produce no artifact}
                            {--local : Write to the configured local override disk regardless of the default backup disk}
                            {--prune : After a successful backup, prune older artifacts per the retention policy}';

    protected $description = 'Create an encrypted database backup using the configured disk and encryption mode';

    public function handle(BackupService $service, BackupRuntimeSettings $runtimeSettings): int
    {
        $config = $runtimeSettings->configuration();

        if (($config['enabled'] ?? true) === false) {
            $this->components->info('Backup is disabled in System → Database Backups. Managed-DB deployments may rely on provider snapshots.');

            return self::SUCCESS;
        }

        $diskName = $this->option('local')
            ? (string) ($config['local_disk'] ?? 'local')
            : (string) ($config['disk'] ?? 'local');

        $mode = (string) ($config['encryption']['mode'] ?? 'app-key');
        if ($mode === 'none') {
            $this->components->warn('Encryption mode is "none": the artifact is plaintext. Acceptable only for deployments with no sensitive data.');
        }

        if ($mode === 'app-key') {
            $mismatches = $service->findFingerprintMismatches($config, $diskName);
            if ($mismatches !== []) {
                $this->components->error('APP_KEY has changed since these backups were created. Run `php artisan blb:key:rotate` or `blb:db:backup:rekey --old-key=<base64key> --commit` to re-wrap DEKs before running a new backup.');
                foreach ($mismatches as $backupId) {
                    $this->components->twoColumnDetail('Stale fingerprint', $backupId);
                }

                return self::FAILURE;
            }
        }

        $dryRun = (bool) $this->option('dry-run');

        try {
            $result = $service->backup(
                config: $config,
                diskName: $diskName,
                trigger: $this->resolveTrigger(),
                dryRun: $dryRun,
            );
        } catch (BackupException $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        } catch (Throwable $e) {
            $this->components->error('Backup failed: '.$e->getMessage());

            return self::FAILURE;
        }

        if ($result->dryRun) {
            $this->components->info('Dry run OK.');
            $this->components->twoColumnDetail('Driver', $result->driver);
            $this->components->twoColumnDetail('Encryption', $result->encryptionMode);
            $this->components->twoColumnDetail('Disk', $diskName);

            return self::SUCCESS;
        }

        $manifest = $result->manifest;
        $this->components->info('Backup created.');
        if ($manifest !== null) {
            $this->components->twoColumnDetail('Backup ID', $manifest->backupId);
            $this->components->twoColumnDetail('Driver', $manifest->driver);
            $this->components->twoColumnDetail('Encryption', $manifest->encryptionMode);
            $this->components->twoColumnDetail('Disk', $diskName);
            $this->components->twoColumnDetail('Artifact', (string) $result->artifactPath);
            $this->components->twoColumnDetail('Manifest', (string) $result->manifestPath);
            $this->components->twoColumnDetail('Size (bytes)', (string) $manifest->sizeBytes);
            $this->components->twoColumnDetail('SHA-256', $manifest->sha256);
        }

        if ($this->option('prune')) {
            $this->prune($service, $config, $diskName);
        }

        return self::SUCCESS;
    }

    private function prune(BackupService $service, array $config, string $diskName): void
    {
        $policy = new RetentionPolicy(
            keepDays: (int) ($config['retention']['keep_days'] ?? 0),
            keepCount: (int) ($config['retention']['keep_count'] ?? 0),
        );

        if ($policy->keepDays <= 0) {
            $this->components->info('Retention pruning skipped: keep_days <= 0.');

            return;
        }

        $entries = $service->listManifestEntries($config, $diskName);
        $expired = $policy->selectExpired($entries, time());

        if ($expired === []) {
            $this->components->info('Retention: nothing to prune.');

            return;
        }

        $removed = $service->deleteEntries($diskName, $expired);
        $this->components->info('Pruned '.count($expired).' backup(s); '.count($removed).' object(s) removed.');
    }

    private function resolveTrigger(): string
    {
        $user = function_exists('posix_getpwuid')
            ? (posix_getpwuid(posix_geteuid())['name'] ?? 'unknown')
            : 'unknown';

        return "console:{$user}";
    }
}
