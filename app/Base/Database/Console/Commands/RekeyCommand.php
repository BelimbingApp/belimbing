<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Base\Database\Console\Commands;

use App\Base\Database\Services\Backup\Encryption\AppKeyEncryption;
use App\Base\Database\Services\Backup\Manifest;
use Illuminate\Console\Command;
use Illuminate\Filesystem\FilesystemManager;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * Re-wrap every app-key manifest's DEK under the current APP_KEY.
 *
 * Run this command whenever APP_KEY changes — either via `blb:key:rotate`
 * (which calls this automatically with `--old-key` and `--commit`) or
 * after a manual `key:generate` that was applied without using `blb:key:rotate`.
 *
 * Idempotent: manifests whose kek_fingerprint already matches the current
 * APP_KEY are skipped silently. Manifests that cannot be decrypted by either
 * the current or the `--old-key` KEK are listed as "stuck" and the command
 * exits non-zero.
 *
 * Dry-run by default. Pass `--commit` to write the updated manifests.
 */
#[AsCommand(name: 'blb:db:backup:rekey')]
final class RekeyCommand extends Command
{
    protected $signature = 'blb:db:backup:rekey
                            {--old-key= : Base64-encoded old APP_KEY (may include "base64:" prefix) used to decrypt DEKs before re-wrapping}
                            {--commit   : Persist updated manifests (dry-run by default)}';

    protected $description = 'Re-wrap database-backup DEKs under the current APP_KEY after key rotation';

    public function handle(FilesystemManager $fsm): int
    {
        $config = (array) config('backup', []);
        $diskName = (string) ($config['disk'] ?? 'local');
        $disk = $fsm->disk($diskName);

        // Derive KEK + fingerprint for current APP_KEY.
        $currentRawKey = $this->decodeKeyOption((string) config('app.key', ''), 'current APP_KEY');
        if ($currentRawKey === null) {
            // decodeKeyOption already printed an operator-facing error.
            return self::FAILURE;
        }
        $currentKek = AppKeyEncryption::deriveKekFromRaw($currentRawKey);
        $currentFingerprint = base64_encode(AppKeyEncryption::fingerprintFromKek($currentKek));
        sodium_memzero($currentRawKey);

        // Optionally decode old APP_KEY.
        $oldKek = null;
        $oldKeyOpt = $this->option('old-key');
        if ($oldKeyOpt !== null && $oldKeyOpt !== '') {
            $oldRaw = $this->decodeKeyOption((string) $oldKeyOpt, '--old-key');
            if ($oldRaw === null) {
                sodium_memzero($currentKek);

                return self::FAILURE;
            }
            $oldKek = AppKeyEncryption::deriveKekFromRaw($oldRaw);
            sodium_memzero($oldRaw);
        }

        $commit = (bool) $this->option('commit');
        $prefix = trim((string) ($config['path_prefix'] ?? 'backups'), '/');
        $environment = (string) config('app.env', 'production');
        $directory = $prefix === '' ? $environment : "{$prefix}/{$environment}";

        $skipped = 0;
        $rekeyed = 0;
        $stuck = [];

        foreach ($disk->files($directory) as $file) {
            if (! str_ends_with($file, '.manifest.json')) {
                continue;
            }

            $raw = $disk->get($file);
            if ($raw === null) {
                $this->components->warn("Could not read {$file} — skipping.");
                continue;
            }

            $data = json_decode($raw, true);
            if (! is_array($data) || ($data['encryption_mode'] ?? '') !== 'app-key') {
                continue; // Not an app-key manifest.
            }

            $backupId = (string) ($data['backup_id'] ?? $file);
            $manifestFingerprint = (string) ($data['kek_fingerprint'] ?? '');

            // Already on the current key — idempotent skip.
            if ($manifestFingerprint !== '' && $manifestFingerprint === $currentFingerprint) {
                $skipped++;
                continue;
            }

            $wrappedDek = isset($data['wrapped_dek']) ? base64_decode((string) $data['wrapped_dek'], strict: true) : false;
            $dekNonce = isset($data['dek_nonce']) ? base64_decode((string) $data['dek_nonce'], strict: true) : false;

            if ($wrappedDek === false || $dekNonce === false) {
                $this->components->warn("Manifest {$backupId} has corrupt or missing wrapped_dek/dek_nonce.");
                $stuck[] = $backupId;
                continue;
            }

            // Try current KEK first (idempotent safety), then old KEK.
            $dek = @sodium_crypto_secretbox_open($wrappedDek, $dekNonce, $currentKek);
            if ($dek === false && $oldKek !== null) {
                $dek = @sodium_crypto_secretbox_open($wrappedDek, $dekNonce, $oldKek);
            }

            if ($dek === false) {
                $this->components->warn("Cannot unwrap DEK for {$backupId} with current or old key.");
                $stuck[] = $backupId;
                continue;
            }

            // Re-wrap with a fresh nonce under the current KEK.
            $newNonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
            $newWrapped = sodium_crypto_secretbox($dek, $newNonce, $currentKek);
            sodium_memzero($dek);

            $newFingerprint = $currentFingerprint;

            $data['wrapped_dek'] = base64_encode($newWrapped);
            $data['dek_nonce'] = base64_encode($newNonce);
            $data['kek_fingerprint'] = $newFingerprint;

            if ($commit) {
                $disk->put($file, (string) json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
                $this->components->twoColumnDetail('Re-keyed', $backupId);
            } else {
                $this->components->twoColumnDetail('Would re-key', $backupId);
            }

            $rekeyed++;
        }

        sodium_memzero($currentKek);
        if ($oldKek !== null) {
            sodium_memzero($oldKek);
        }

        $this->newLine();
        $this->components->twoColumnDetail('Skipped (already current)', (string) $skipped);
        $this->components->twoColumnDetail('Re-keyed', (string) $rekeyed);
        $this->components->twoColumnDetail('Stuck (cannot unwrap)', (string) count($stuck));

        if ($stuck !== []) {
            $this->newLine();
            $this->components->error('Some manifests could not be re-keyed. Recover the old APP_KEY and re-run with --old-key=<key>.');
            foreach ($stuck as $backupId) {
                $this->components->twoColumnDetail('Stuck', $backupId);
            }

            return self::FAILURE;
        }

        if (! $commit && $rekeyed > 0) {
            $this->newLine();
            $this->components->info('Dry run complete. Pass --commit to write changes.');
        }

        return self::SUCCESS;
    }

    /**
     * Decode a raw or base64:-prefixed APP_KEY string into 32 bytes.
     * Prints an error and returns null on failure.
     */
    private function decodeKeyOption(string $keyString, string $label): ?string
    {
        if ($keyString === '') {
            $this->components->error("The {$label} is empty.");

            return null;
        }

        $raw = str_starts_with($keyString, 'base64:')
            ? base64_decode(substr($keyString, 7), strict: true)
            : $keyString;

        if ($raw === false || strlen($raw) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
            $this->components->error("The {$label} does not decode to 32 bytes. Provide the full base64:-prefixed value from .env.");

            return null;
        }

        return $raw;
    }
}
