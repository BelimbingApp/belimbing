<?php

use App\Base\Database\Services\Backup\Encryption\AppKeyEncryption;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

uses(TestCase::class);

/**
 * Helpers shared by rekey tests.
 */

/** Set a fresh 32-byte random APP_KEY and return the raw key string. */
function rkSetKey(): string
{
    $raw = random_bytes(32);
    $key = 'base64:'.base64_encode($raw);
    config(['app.key' => $key]);

    return $key;
}

/** Build a minimal app-key manifest array and write it to the faked disk. */
function rkPlantManifest(string $backupId, string $wrappedDek, string $dekNonce, string $kekFingerprint, string $directory = 'backups/testing'): string
{
    $path = "{$directory}/{$backupId}.manifest.json";
    Storage::disk('local')->put($path, (string) json_encode([
        'backup_id' => $backupId,
        'encryption_mode' => 'app-key',
        'wrapped_dek' => $wrappedDek,
        'dek_nonce' => $dekNonce,
        'kek_fingerprint' => $kekFingerprint,
        'status' => 'success',
    ]));

    return $path;
}

/** Encrypt a DEK under a specific APP_KEY string, return [wrappedDek, dekNonce, kekFingerprint] (all base64). */
function rkEncryptDek(string $appKey): array
{
    $raw = str_starts_with($appKey, 'base64:')
        ? base64_decode(substr($appKey, 7), strict: true)
        : $appKey;

    $dek = random_bytes(32);
    $kek = AppKeyEncryption::deriveKekFromRaw($raw);
    $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
    $wrapped = sodium_crypto_secretbox($dek, $nonce, $kek);
    $fp = AppKeyEncryption::fingerprintFromKek($kek);

    return [
        base64_encode($wrapped),
        base64_encode($nonce),
        base64_encode($fp),
    ];
}

beforeEach(function (): void {
    Storage::fake('local');
    config()->set('backup.disk', 'local');
    config()->set('backup.path_prefix', 'backups');
});

it('re-wraps DEK under the current KEK and updates the fingerprint', function (): void {
    $oldKey = rkSetKey();
    [$wrappedDek, $dekNonce, $oldFp] = rkEncryptDek($oldKey);

    $manifestPath = rkPlantManifest('backup-1', $wrappedDek, $dekNonce, $oldFp);

    // Rotate to a new APP_KEY.
    $newKey = rkSetKey();
    $newFp = AppKeyEncryption::currentFingerprint();

    $this->artisan("blb:db:backup:rekey --old-key={$oldKey} --commit")
        ->assertExitCode(0);

    $data = json_decode((string) Storage::disk('local')->get($manifestPath), true);

    expect($data['kek_fingerprint'])->toBe($newFp);
    expect($data['kek_fingerprint'])->not->toBe($oldFp);

    // Verify new DEK can be unwrapped with new KEK.
    $newRaw = base64_decode(substr($newKey, 7), strict: true);
    $newKek = AppKeyEncryption::deriveKekFromRaw($newRaw);
    $dek = sodium_crypto_secretbox_open(
        base64_decode($data['wrapped_dek'], strict: true),
        base64_decode($data['dek_nonce'], strict: true),
        $newKek,
    );
    expect($dek)->not->toBeFalse();
    expect(strlen($dek))->toBe(32);
});

it('skips a manifest whose fingerprint already matches the current key (idempotent)', function (): void {
    $currentKey = rkSetKey();
    [$wrappedDek, $dekNonce, $currentFp] = rkEncryptDek($currentKey);

    $manifestPath = rkPlantManifest('backup-already-current', $wrappedDek, $dekNonce, $currentFp);

    $originalContent = Storage::disk('local')->get($manifestPath);

    $this->artisan("blb:db:backup:rekey --commit")->assertExitCode(0);

    // Manifest content must not have changed.
    expect(Storage::disk('local')->get($manifestPath))->toBe($originalContent);
});

it('is idempotent: second rekey pass skips all', function (): void {
    $oldKey = rkSetKey();
    [$wrappedDek, $dekNonce, $oldFp] = rkEncryptDek($oldKey);

    rkPlantManifest('backup-idem', $wrappedDek, $dekNonce, $oldFp);

    $newKey = rkSetKey();

    // First pass: re-wraps.
    $this->artisan("blb:db:backup:rekey --old-key={$oldKey} --commit")
        ->assertExitCode(0);

    $contentAfterFirst = Storage::disk('local')->get('backups/testing/backup-idem.manifest.json');

    // Second pass: nothing to do (already on current fingerprint).
    $this->artisan('blb:db:backup:rekey --commit')->assertExitCode(0);

    $contentAfterSecond = Storage::disk('local')->get('backups/testing/backup-idem.manifest.json');

    expect($contentAfterSecond)->toBe($contentAfterFirst);
});

it('lists stuck manifests and exits non-zero when old key not provided for mismatched fingerprint', function (): void {
    $oldKey = rkSetKey();
    [$wrappedDek, $dekNonce, $oldFp] = rkEncryptDek($oldKey);

    rkPlantManifest('backup-stuck', $wrappedDek, $dekNonce, $oldFp);

    // Rotate to a new key WITHOUT providing --old-key.
    rkSetKey();

    $this->artisan('blb:db:backup:rekey --commit')
        ->expectsOutputToContain('Cannot unwrap DEK')
        ->assertExitCode(1);
});

it('dry-run by default: does not write manifest changes', function (): void {
    $oldKey = rkSetKey();
    [$wrappedDek, $dekNonce, $oldFp] = rkEncryptDek($oldKey);

    $manifestPath = rkPlantManifest('backup-dry', $wrappedDek, $dekNonce, $oldFp);
    $originalContent = Storage::disk('local')->get($manifestPath);

    rkSetKey(); // New key.

    // Without --commit → dry run.
    $this->artisan("blb:db:backup:rekey --old-key={$oldKey}")
        ->expectsOutputToContain('Would re-key')
        ->assertExitCode(0);

    // Content unchanged.
    expect(Storage::disk('local')->get($manifestPath))->toBe($originalContent);
});

it('ignores non-app-key manifests on the disk', function (): void {
    $currentKey = rkSetKey();

    // Plant a none-mode manifest with a stale fingerprint.
    Storage::disk('local')->put('backups/testing/old-none.manifest.json', (string) json_encode([
        'backup_id' => 'old-none',
        'encryption_mode' => 'none',
        'kek_fingerprint' => base64_encode('stale'),
        'status' => 'success',
    ]));

    $this->artisan('blb:db:backup:rekey --commit')
        ->assertExitCode(0);

    // The none-mode manifest must not have been touched.
    $data = json_decode((string) Storage::disk('local')->get('backups/testing/old-none.manifest.json'), true);
    expect($data['kek_fingerprint'])->toBe(base64_encode('stale'));
});

it('recovers from a partial rekey: mixed disk re-runs cleanly with --old-key', function (): void {
    // Simulate a previous rekey that crashed halfway: one manifest already on the
    // new key, one still on the old. A re-run with --old-key must finish the job.
    $oldKey = rkSetKey();
    [$wOld, $nOld, $fpOld] = rkEncryptDek($oldKey);
    rkPlantManifest('backup-still-old', $wOld, $nOld, $fpOld);

    $newKey = rkSetKey();
    [$wNew, $nNew, $fpNew] = rkEncryptDek($newKey);
    rkPlantManifest('backup-already-new', $wNew, $nNew, $fpNew);

    $this->artisan("blb:db:backup:rekey --old-key={$oldKey} --commit")
        ->assertExitCode(0);

    $currentFp = AppKeyEncryption::currentFingerprint();

    foreach (['backup-still-old', 'backup-already-new'] as $id) {
        $data = json_decode(
            (string) Storage::disk('local')->get("backups/testing/{$id}.manifest.json"),
            true,
        );
        expect($data['kek_fingerprint'])->toBe($currentFp);
    }

    // The manifest that was already on the new key should be byte-identical to
    // before — idempotent skip, not a re-wrap with a fresh nonce.
    $alreadyNew = json_decode(
        (string) Storage::disk('local')->get('backups/testing/backup-already-new.manifest.json'),
        true,
    );
    expect($alreadyNew['wrapped_dek'])->toBe($wNew);
    expect($alreadyNew['dek_nonce'])->toBe($nNew);
});

it('blb:key:rotate leaves all fingerprints matching the new APP_KEY', function (): void {
    // Set up initial APP_KEY and write a manifest encrypted under it.
    $initialKey = rkSetKey();
    [$wrappedDek, $dekNonce, $initialFp] = rkEncryptDek($initialKey);

    rkPlantManifest('pre-rotate-backup', $wrappedDek, $dekNonce, $initialFp);

    // Point the application at a sandboxed .env so the test never touches the
    // operator's real file. KeyRotateCommand reads $this->laravel->environmentFilePath().
    $envDir = sys_get_temp_dir();
    $envFile = 'blb-rotate-test-'.bin2hex(random_bytes(4)).'.env';
    $envFullPath = $envDir.DIRECTORY_SEPARATOR.$envFile;
    file_put_contents($envFullPath, "APP_KEY={$initialKey}\n");

    $originalEnvPath = app()->environmentPath();
    $originalEnvFile = app()->environmentFile();
    app()->useEnvironmentPath($envDir);
    app()->loadEnvironmentFrom($envFile);

    try {
        $this->artisan('blb:key:rotate')->assertExitCode(0);

        // All app-key manifests should now have the new fingerprint.
        $newFp = AppKeyEncryption::currentFingerprint();

        foreach (Storage::disk('local')->files('backups/testing') as $file) {
            if (! str_ends_with($file, '.manifest.json')) {
                continue;
            }
            $data = json_decode((string) Storage::disk('local')->get($file), true);
            if (($data['encryption_mode'] ?? '') !== 'app-key') {
                continue;
            }
            expect($data['kek_fingerprint'])->toBe($newFp);
        }

        // The sandbox .env was rewritten with the new key.
        expect(file_get_contents($envFullPath))->toContain('APP_KEY=base64:');
        expect(file_get_contents($envFullPath))->not->toContain($initialKey);
    } finally {
        app()->useEnvironmentPath($originalEnvPath);
        app()->loadEnvironmentFrom($originalEnvFile);
        @unlink($envFullPath);
    }
});
