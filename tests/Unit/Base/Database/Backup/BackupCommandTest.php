<?php

use App\Base\Database\Exceptions\BackupException;
use App\Base\Database\Services\Backup\Encryption\EncryptionMode;
use App\Base\Database\Services\Backup\Encryption\EncryptionModeRegistry;
use App\Base\Database\Services\Backup\Encryption\NoneEncryption;
use App\Base\Settings\Contracts\SettingsService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

uses(TestCase::class, LazilyRefreshDatabase::class);

/**
 * Set up a fresh on-disk SQLite database with one tiny table and a few rows,
 * register it as the default connection for the duration of the test, and
 * point the backup module at a faked local disk.
 *
 * Returns the absolute path of the source SQLite file so callers can clean up
 * and inspect it.
 */
function bcSetupSqliteEnvironment(): string
{
    $sourcePath = sys_get_temp_dir().'/blb-bcmd-src-'.bin2hex(random_bytes(4)).'.sqlite';
    @unlink($sourcePath);
    touch($sourcePath);

    config()->set('database.connections.backup_source', [
        'driver' => 'sqlite',
        'database' => $sourcePath,
        'prefix' => '',
        'foreign_key_constraints' => true,
    ]);
    config()->set('database.default', 'backup_source');

    DB::purge('backup_source');

    DB::connection('backup_source')->statement(
        'CREATE TABLE widgets (id INTEGER PRIMARY KEY, name TEXT NOT NULL)'
    );
    DB::connection('backup_source')->statement(
        'CREATE TABLE base_settings (id INTEGER PRIMARY KEY AUTOINCREMENT, key TEXT NOT NULL, value TEXT, is_encrypted INTEGER NOT NULL DEFAULT 0, scope_type TEXT, scope_id INTEGER, created_at TEXT, updated_at TEXT)'
    );
    DB::connection('backup_source')->statement(
        'CREATE UNIQUE INDEX base_settings_scope_unique ON base_settings (key, scope_type, scope_id)'
    );
    DB::connection('backup_source')->insert("INSERT INTO widgets (id, name) VALUES (1, 'apple'), (2, 'belimbing'), (3, 'cherry')");

    Storage::fake('local');

    app(SettingsService::class)->set('backup.disk', 'local');
    config()->set('backup.local_disk', 'local');
    app(SettingsService::class)->set('backup.path_prefix', 'backups');
    app(SettingsService::class)->set('backup.encryption.mode', 'app-key');

    return $sourcePath;
}

function bcCleanup(string $sourcePath): void
{
    DB::purge('backup_source');
    @unlink($sourcePath);
}

function bcRequireSodium(): void
{
    if (! extension_loaded('sodium')) {
        test()->markTestSkipped('The app-key backup mode requires ext-sodium.');
    }
}

/** @return array{root: string, artifact: string, manifest: string, output: string} */
function bcStageFiles(string $bytes, array $manifestData): array
{
    $root = sys_get_temp_dir().'/blb-backup-stage-'.bin2hex(random_bytes(4));
    mkdir($root);
    $artifact = $root.'/source.bak';
    $manifest = $root.'/source.manifest.json';
    $output = $root.'/staged.bak';
    file_put_contents($artifact, $bytes);
    $manifestData['sha256'] ??= hash_file('sha256', $artifact);
    file_put_contents($manifest, json_encode($manifestData, JSON_THROW_ON_ERROR));

    return compact('root', 'artifact', 'manifest', 'output');
}

function bcCleanupStageFiles(array $files): void
{
    @unlink($files['artifact']);
    @unlink($files['manifest']);
    @unlink($files['output']);
    @rmdir($files['root']);
}

it('dry-run validates configuration and writes nothing', function (): void {
    bcRequireSodium();
    $src = bcSetupSqliteEnvironment();

    try {
        $this->artisan('blb:db:backup --dry-run')
            ->expectsOutputToContain('Dry run OK.')
            ->assertExitCode(0);

        $files = Storage::disk('local')->allFiles('backups');
        expect($files)->toBe([]);
    } finally {
        bcCleanup($src);
    }
});

it('creates an encrypted artifact and manifest in app-key mode', function (): void {
    bcRequireSodium();
    $src = bcSetupSqliteEnvironment();

    try {
        $this->artisan('blb:db:backup')->assertExitCode(0);

        $files = Storage::disk('local')->allFiles('backups');
        $artifacts = array_values(array_filter($files, fn ($f) => str_ends_with($f, '.bak.enc')));
        $manifests = array_values(array_filter($files, fn ($f) => str_ends_with($f, '.manifest.json')));

        expect($artifacts)->toHaveCount(1);
        expect($manifests)->toHaveCount(1);

        // Artifact is ciphertext — does not contain plaintext DB content.
        $artifactBytes = Storage::disk('local')->get($artifacts[0]);
        expect(str_contains((string) $artifactBytes, 'widgets'))->toBeFalse();
        expect(str_contains((string) $artifactBytes, 'belimbing'))->toBeFalse();

        // Manifest contains operational facts and envelope fields.
        $manifest = json_decode((string) Storage::disk('local')->get($manifests[0]), true);
        expect($manifest['driver'])->toBe('sqlite');
        expect($manifest['encryption_mode'])->toBe('app-key');
        expect($manifest['status'])->toBe('success');
        expect($manifest['size_bytes'])->toBeGreaterThan(0);
        expect($manifest['sha256'])->toMatch('/^[0-9a-f]{64}$/');
        expect($manifest)->toHaveKey('wrapped_dek');
        expect($manifest)->toHaveKey('dek_nonce');
        expect($manifest)->toHaveKey('kek_fingerprint');
        // Envelope fields must decode to the exact sizes mandated by the
        // encryption contract: 32-byte DEK + 16-byte Poly1305 MAC = 48 bytes;
        // 24-byte secretbox nonce; 8-byte HKDF fingerprint.
        expect(strlen((string) base64_decode($manifest['wrapped_dek'], strict: true)))->toBe(48);
        expect(strlen((string) base64_decode($manifest['dek_nonce'], strict: true)))->toBe(24);
        expect(strlen((string) base64_decode($manifest['kek_fingerprint'], strict: true)))->toBe(8);
    } finally {
        bcCleanup($src);
    }
});

it('warns and writes plain artifact in none mode', function (): void {
    $src = bcSetupSqliteEnvironment();
    app(SettingsService::class)->set('backup.encryption.mode', 'none');

    try {
        $this->artisan('blb:db:backup')
            ->expectsOutputToContain('Encryption mode is "none"')
            ->assertExitCode(0);

        $files = Storage::disk('local')->allFiles('backups');
        $artifacts = array_values(array_filter($files, fn ($f) => str_ends_with($f, '.bak')));
        expect($artifacts)->toHaveCount(1);

        // none-mode artifact is a SQLite snapshot — header starts with the SQLite magic.
        $bytes = (string) Storage::disk('local')->get($artifacts[0]);
        expect(substr($bytes, 0, 16))->toBe("SQLite format 3\x00");
    } finally {
        bcCleanup($src);
    }
});

it('--prune deletes only artifacts older than keep_days while preserving keep_count', function (): void {
    $src = bcSetupSqliteEnvironment();

    // Aggressive policy: prune anything older than 1 day; keep at least 1 newest.
    app(SettingsService::class)->set('backup.encryption.mode', 'none');
    app(SettingsService::class)->set('backup.retention.keep_days', 1);
    app(SettingsService::class)->set('backup.retention.keep_count', 1);

    try {
        // Two backups (newest + older); we'll backdate the older one.
        $this->artisan('blb:db:backup')->assertExitCode(0);
        $manifestPaths = collect(Storage::disk('local')->allFiles('backups'))
            ->filter(fn ($f) => str_ends_with($f, '.manifest.json'))
            ->values();
        expect($manifestPaths)->toHaveCount(1);
        $oldManifestPath = $manifestPaths[0];

        // Rewrite the first manifest's finished_at to 10 days ago.
        $oldManifest = json_decode((string) Storage::disk('local')->get($oldManifestPath), true);
        $oldManifest['finished_at'] = now()->subDays(10)->toIso8601String();
        Storage::disk('local')->put($oldManifestPath, (string) json_encode($oldManifest));

        $this->artisan('blb:db:backup --prune')->assertExitCode(0);

        $remaining = collect(Storage::disk('local')->allFiles('backups'))
            ->filter(fn ($f) => str_ends_with($f, '.manifest.json'))
            ->values();

        // Only the new backup remains; the backdated one was pruned.
        expect($remaining)->toHaveCount(1);
        expect($remaining[0])->not->toBe($oldManifestPath);
    } finally {
        bcCleanup($src);
    }
});

it('skips work when backup.enabled is false', function (): void {
    $src = bcSetupSqliteEnvironment();
    app(SettingsService::class)->set('backup.enabled', false);

    try {
        $this->artisan('blb:db:backup')
            ->expectsOutputToContain('Backup is disabled')
            ->assertExitCode(0);

        $files = Storage::disk('local')->allFiles('backups');
        expect($files)->toBe([]);
    } finally {
        bcCleanup($src);
    }
});

it('resolves an extension-registered encryption mode via the registry', function (): void {
    $src = bcSetupSqliteEnvironment();

    try {
        // Register a custom mode that aliases 'none' — no real crypto needed for this test.
        app(EncryptionModeRegistry::class)->register(
            'ext-test-noop',
            fn (array $config) => new NoneEncryption,
        );

        app(SettingsService::class)->set('backup.encryption.mode', 'ext-test-noop');

        $this->artisan('blb:db:backup --dry-run')
            ->expectsOutputToContain('Dry run OK.')
            ->assertExitCode(0);
    } finally {
        bcCleanup($src);
    }
});

it('aborts with fingerprint mismatch when kek_fingerprint in manifest differs from current APP_KEY', function (): void {
    bcRequireSodium();
    $src = bcSetupSqliteEnvironment();

    try {
        // First backup with current APP_KEY.
        $this->artisan('blb:db:backup')->assertExitCode(0);

        $manifests = collect(Storage::disk('local')->allFiles('backups'))
            ->filter(fn ($f) => str_ends_with($f, '.manifest.json'))
            ->values();
        expect($manifests)->toHaveCount(1);

        // Overwrite the kek_fingerprint to simulate a stale key.
        $data = json_decode((string) Storage::disk('local')->get($manifests[0]), true);
        $data['kek_fingerprint'] = base64_encode('stale-fp');
        Storage::disk('local')->put($manifests[0], (string) json_encode($data));

        $this->artisan('blb:db:backup')
            ->expectsOutputToContain('APP_KEY has changed')
            ->assertExitCode(1);
    } finally {
        bcCleanup($src);
    }
});

it('preflight ignores non-app-key manifests on the disk', function (): void {
    bcRequireSodium();
    $src = bcSetupSqliteEnvironment();

    try {
        // Plant a none-mode manifest with a bad fingerprint; should not trigger preflight.
        $fakeManifest = [
            'backup_id' => 'old-none-backup',
            'encryption_mode' => 'none',
            'kek_fingerprint' => base64_encode('stale-fp'),
            'status' => 'success',
        ];
        Storage::disk('local')->put(
            'backups/testing/old-none-backup.manifest.json',
            (string) json_encode($fakeManifest)
        );

        // Backup should succeed because the stale manifest is not app-key mode.
        $this->artisan('blb:db:backup')->assertExitCode(0);
    } finally {
        bcCleanup($src);
    }
});

it('stages a verified plaintext artifact without overwriting output', function (): void {
    $files = bcStageFiles('verified backup bytes', [
        'backup_id' => 'stage-test',
        'encryption_mode' => 'none',
    ]);
    extract($files);

    $queries = [];
    DB::listen(function ($query) use (&$queries): void {
        $queries[] = $query->sql;
    });

    try {
        $this->artisan('blb:db:backup:stage', [
            'artifact' => $artifact,
            'manifest' => $manifest,
            'output' => $output,
        ])
            ->expectsOutputToContain('Backup staged without connecting to a database.')
            ->assertSuccessful();

        expect(file_get_contents($output))->toBe('verified backup bytes')
            ->and($queries)->toBe([]);

        $this->artisan('blb:db:backup:stage', [
            'artifact' => $artifact,
            'manifest' => $manifest,
            'output' => $output,
        ])
            ->expectsOutputToContain('refusing to overwrite')
            ->assertFailed();
        expect(file_get_contents($output))->toBe('verified backup bytes');
    } finally {
        bcCleanupStageFiles($files);
    }
});

it('preserves a destination created while staging is in progress', function (): void {
    $files = bcStageFiles('verified backup bytes', [
        'backup_id' => 'stage-race-test',
        'encryption_mode' => 'race-test',
    ]);
    extract($files);
    app(EncryptionModeRegistry::class)->register('race-test', function (array $_config) use ($output): NoneEncryption {
        file_put_contents($output, 'created by another process');

        return new NoneEncryption;
    });

    try {
        $this->artisan('blb:db:backup:stage', [
            'artifact' => $artifact,
            'manifest' => $manifest,
            'output' => $output,
        ])
            ->expectsOutputToContain('refusing to overwrite')
            ->assertFailed();

        expect(file_get_contents($output))->toBe('created by another process');
    } finally {
        bcCleanupStageFiles($files);
    }
});

it('cleans its private staging directory when decryption fails', function (): void {
    $files = bcStageFiles('verified backup bytes', [
        'backup_id' => 'stage-decrypt-failure-test',
        'encryption_mode' => 'failing-test',
    ]);
    extract($files);
    $mode = Mockery::mock(EncryptionMode::class);
    $mode->shouldReceive('ensureReady')->once();
    $mode->shouldReceive('decryptFile')->once()->andReturnUsing(function (string $_source, string $destination): void {
        file_put_contents($destination, 'partial plaintext');

        throw BackupException::restoreFailed('simulated decryption failure');
    });
    $mode->shouldNotReceive('name', 'extension', 'encryptFile');
    app(EncryptionModeRegistry::class)->register('failing-test', fn (array $_config): EncryptionMode => $mode);

    try {
        $this->artisan('blb:db:backup:stage', [
            'artifact' => $artifact,
            'manifest' => $manifest,
            'output' => $output,
        ])
            ->expectsOutputToContain('simulated decryption failure')
            ->assertFailed();

        expect($output)->not->toBeFile()
            ->and(glob($root.'/.blb-stage-*'))->toBe([]);
    } finally {
        bcCleanupStageFiles($files);
    }
});

it('refuses to stage an artifact whose manifest hash does not match', function (): void {
    $files = bcStageFiles('changed backup bytes', [
        'backup_id' => 'stage-test',
        'encryption_mode' => 'none',
        'sha256' => str_repeat('0', 64),
    ]);
    extract($files);

    try {
        $this->artisan('blb:db:backup:stage', [
            'artifact' => $artifact,
            'manifest' => $manifest,
            'output' => $output,
        ])
            ->expectsOutputToContain('Artifact SHA-256 does not match')
            ->assertFailed();

        expect($output)->not->toBeFile();
    } finally {
        bcCleanupStageFiles($files);
    }
});
