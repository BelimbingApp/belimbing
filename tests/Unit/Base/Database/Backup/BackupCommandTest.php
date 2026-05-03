<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

uses(TestCase::class);

/**
 * Set up a fresh on-disk SQLite database with one tiny table and a few rows,
 * register it as the default connection for the duration of the test, and
 * point the backup module at a faked local disk.
 *
 * Returns the absolute path of the source SQLite file so callers can clean up
 * and inspect it.
 */
function bcSetupSqliteEnvironment(string $passphrase = 'test-pp'): string
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
    DB::connection('backup_source')->insert("INSERT INTO widgets (id, name) VALUES (1, 'apple'), (2, 'belimbing'), (3, 'cherry')");

    Storage::fake('local');

    putenv('BACKUP_PASSPHRASE='.$passphrase);
    config()->set('backup.encryption.passphrase_env', 'BACKUP_PASSPHRASE');
    config()->set('backup.disk', 'local');
    config()->set('backup.local_disk', 'local');
    config()->set('backup.path_prefix', 'backups');
    config()->set('backup.encryption.mode', 'passphrase');
    config()->set('backup.restore.allow_current_database', false);

    return $sourcePath;
}

function bcCleanup(string $sourcePath): void
{
    putenv('BACKUP_PASSPHRASE');
    DB::purge('backup_source');
    @unlink($sourcePath);
}

it('dry-run validates configuration and writes nothing', function (): void {
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

it('creates an encrypted artifact and manifest in passphrase mode', function (): void {
    $src = bcSetupSqliteEnvironment();

    try {
        $this->artisan('blb:db:backup')->assertExitCode(0);

        $files = Storage::disk('local')->allFiles('backups');
        $artifacts = array_values(array_filter($files, fn ($f) => str_ends_with($f, '.bak.enc')));
        $manifests = array_values(array_filter($files, fn ($f) => str_ends_with($f, '.manifest.json')));

        expect($artifacts)->toHaveCount(1);
        expect($manifests)->toHaveCount(1);

        // Artifact begins with the BLBPASS magic; never plaintext "widgets".
        $artifactBytes = Storage::disk('local')->get($artifacts[0]);
        expect(substr((string) $artifactBytes, 0, 8))->toBe("BLBPASS\x01");
        expect(str_contains((string) $artifactBytes, 'widgets'))->toBeFalse();
        expect(str_contains((string) $artifactBytes, 'belimbing'))->toBeFalse();

        // Manifest contains operational facts and no secrets.
        $manifest = json_decode((string) Storage::disk('local')->get($manifests[0]), true);
        expect($manifest['driver'])->toBe('sqlite');
        expect($manifest['encryption_mode'])->toBe('passphrase');
        expect($manifest['status'])->toBe('success');
        expect($manifest['size_bytes'])->toBeGreaterThan(0);
        expect($manifest['sha256'])->toMatch('/^[0-9a-f]{64}$/');
        expect($manifest)->not->toHaveKey('passphrase');
    } finally {
        bcCleanup($src);
    }
});

it('warns and writes plain artifact in none mode', function (): void {
    $src = bcSetupSqliteEnvironment();
    config()->set('backup.encryption.mode', 'none');

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

it('round-trips: backup then restore reproduces source data', function (): void {
    $src = bcSetupSqliteEnvironment();
    $target = sys_get_temp_dir().'/blb-bcmd-restored-'.bin2hex(random_bytes(4)).'.sqlite';
    @unlink($target);

    try {
        $this->artisan('blb:db:backup')->assertExitCode(0);

        $manifestPath = collect(Storage::disk('local')->allFiles('backups'))
            ->first(fn ($f) => str_ends_with($f, '.manifest.json'));
        expect($manifestPath)->not->toBeNull();
        $manifest = json_decode((string) Storage::disk('local')->get($manifestPath), true);
        $backupId = $manifest['backup_id'];

        $this->artisan("blb:db:restore --backup={$backupId} --target={$target}")
            ->assertExitCode(0);

        expect(file_exists($target))->toBeTrue();

        $pdo = new PDO('sqlite:'.$target);
        $rows = $pdo->query('SELECT name FROM widgets ORDER BY id')->fetchAll(PDO::FETCH_COLUMN);
        expect($rows)->toBe(['apple', 'belimbing', 'cherry']);
    } finally {
        @unlink($target);
        bcCleanup($src);
    }
});

it('refuses to restore over the current application database', function (): void {
    $src = bcSetupSqliteEnvironment();

    try {
        $this->artisan('blb:db:backup')->assertExitCode(0);

        $manifestPath = collect(Storage::disk('local')->allFiles('backups'))
            ->first(fn ($f) => str_ends_with($f, '.manifest.json'));
        $manifest = json_decode((string) Storage::disk('local')->get($manifestPath), true);
        $backupId = $manifest['backup_id'];

        $this->artisan("blb:db:restore --backup={$backupId} --target={$src}")
            ->expectsOutputToContain('Refusing to restore over the current application database.')
            ->assertExitCode(1);
    } finally {
        bcCleanup($src);
    }
});

it('fails restore on wrong passphrase without leaving a partial target', function (): void {
    $src = bcSetupSqliteEnvironment();
    $target = sys_get_temp_dir().'/blb-bcmd-restored-'.bin2hex(random_bytes(4)).'.sqlite';
    @unlink($target);

    try {
        $this->artisan('blb:db:backup')->assertExitCode(0);

        $manifestPath = collect(Storage::disk('local')->allFiles('backups'))
            ->first(fn ($f) => str_ends_with($f, '.manifest.json'));
        $manifest = json_decode((string) Storage::disk('local')->get($manifestPath), true);
        $backupId = $manifest['backup_id'];

        putenv('BACKUP_PASSPHRASE=wrong-passphrase');

        $this->artisan("blb:db:restore --backup={$backupId} --target={$target}")
            ->expectsOutputToContain('Authentication failed')
            ->assertExitCode(1);

        expect(file_exists($target))->toBeFalse();
    } finally {
        @unlink($target);
        bcCleanup($src);
    }
});

it('--prune deletes only artifacts older than keep_days while preserving keep_count', function (): void {
    $src = bcSetupSqliteEnvironment();

    // Aggressive policy: prune anything older than 1 day; keep at least 1 newest.
    config()->set('backup.retention.keep_days', 1);
    config()->set('backup.retention.keep_count', 1);

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
    config()->set('backup.enabled', false);

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
