<?php

use App\Base\Database\Livewire\Backups\Index;
use App\Modules\Core\User\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    setupAuthzRoles();
    Storage::fake('local');
    config()->set('backup.disk', 'local');
    config()->set('backup.path_prefix', 'backups');
    config()->set('backup.encryption.mode', 'none');
});

test('admin sees the backups page with config snapshot', function (): void {
    $this->actingAs(createAdminUser());

    $response = $this->get(route('admin.system.database-backups.index'));

    $response->assertOk()
        ->assertSee('Database Backups')
        ->assertSee('Disk')
        ->assertSee('local')
        ->assertSee('Encryption')
        ->assertSee('No backups yet');
});

test('unauthenticated request is redirected from the backups page', function (): void {
    $this->get(route('admin.system.database-backups.index'))
        ->assertRedirect();
});

test('authenticated user without admin.backup.list capability is denied', function (): void {
    setupAuthzRoles();

    // Build a user without core_admin (no roles attached).
    $user = User::factory()->create();
    $this->actingAs($user);

    $this->get(route('admin.system.database-backups.index'))
        ->assertForbidden();
});

test('verify action reports OK when manifest sha matches artifact', function (): void {
    $this->actingAs(createAdminUser());

    $disk = Storage::disk('local');
    $artifactPath = 'backups/local/test.bak';
    $manifestPath = 'backups/local/test.manifest.json';
    $payload = "fake-backup-bytes\n";
    $disk->put($artifactPath, $payload);
    $disk->put($manifestPath, json_encode([
        'backup_id' => 'bk-test',
        'driver' => 'sqlite',
        'encryption_mode' => 'none',
        'finished_at' => now()->toIso8601String(),
        'size_bytes' => strlen($payload),
        'sha256' => hash('sha256', $payload),
        'status' => 'success',
        'artifact_path' => $artifactPath,
    ]));

    Livewire::test(Index::class)
        ->call('verify', $manifestPath)
        ->assertSet('statusVariant', 'success')
        ->assertSee('Integrity OK');
});

test('verify action flags failure when artifact bytes differ from manifest', function (): void {
    $this->actingAs(createAdminUser());

    $disk = Storage::disk('local');
    $artifactPath = 'backups/local/tampered.bak';
    $manifestPath = 'backups/local/tampered.manifest.json';
    $disk->put($artifactPath, 'tampered-bytes');
    $disk->put($manifestPath, json_encode([
        'backup_id' => 'bk-bad',
        'driver' => 'sqlite',
        'encryption_mode' => 'none',
        'finished_at' => now()->toIso8601String(),
        'size_bytes' => 14,
        'sha256' => str_repeat('0', 64),
        'status' => 'success',
        'artifact_path' => $artifactPath,
    ]));

    Livewire::test(Index::class)
        ->call('verify', $manifestPath)
        ->assertSet('statusVariant', 'danger')
        ->assertSee('Integrity FAILED');
});

test('delete action removes the manifest and artifact pair', function (): void {
    $this->actingAs(createAdminUser());

    $disk = Storage::disk('local');
    $artifactPath = 'backups/local/will-be-deleted.bak';
    $manifestPath = 'backups/local/will-be-deleted.manifest.json';
    $disk->put($artifactPath, 'bytes');
    $disk->put($manifestPath, json_encode([
        'backup_id' => 'bk-del',
        'driver' => 'sqlite',
        'encryption_mode' => 'none',
        'finished_at' => now()->toIso8601String(),
        'size_bytes' => 5,
        'sha256' => hash('sha256', 'bytes'),
        'status' => 'success',
        'artifact_path' => $artifactPath,
    ]));

    Livewire::test(Index::class)
        ->call('delete', $manifestPath)
        ->assertSet('statusVariant', 'success');

    expect($disk->exists($artifactPath))->toBeFalse();
    expect($disk->exists($manifestPath))->toBeFalse();
});

test('disabled backup config short-circuits the run-backup action', function (): void {
    $this->actingAs(createAdminUser());
    config()->set('backup.enabled', false);

    Livewire::test(Index::class)
        ->call('runBackup')
        ->assertSet('statusVariant', 'warning')
        ->assertSee('Backup is disabled');
});

test('preflight flags missing passphrase env when mode=passphrase and worker has no env var', function (): void {
    $this->actingAs(createAdminUser());

    config()->set('backup.encryption.mode', 'passphrase');
    config()->set('backup.encryption.passphrase_env', 'MISSING_PP_VAR_FOR_TEST');
    putenv('MISSING_PP_VAR_FOR_TEST');

    Livewire::test(Index::class)
        ->assertSet('rows', [])
        ->assertSee('environment variable is not visible to the application worker');
});

test('runBackup accepts an inline passphrase override and never stores it', function (): void {
    $this->actingAs(createAdminUser());

    // Source: a small file-based sqlite DB.
    $sourcePath = sys_get_temp_dir().'/blb-ui-pp-'.bin2hex(random_bytes(4)).'.sqlite';
    @unlink($sourcePath);
    touch($sourcePath);

    config()->set('database.connections.backup_ui_source', [
        'driver' => 'sqlite',
        'database' => $sourcePath,
        'prefix' => '',
        'foreign_key_constraints' => true,
    ]);
    DB::purge('backup_ui_source');
    DB::connection('backup_ui_source')->statement('CREATE TABLE t (id INTEGER PRIMARY KEY)');

    config()->set('backup.connection', 'backup_ui_source');
    config()->set('backup.encryption.mode', 'passphrase');
    config()->set('backup.encryption.passphrase_env', 'MISSING_PP_FOR_OVERRIDE_TEST');
    putenv('MISSING_PP_FOR_OVERRIDE_TEST');

    try {
        $component = Livewire::test(Index::class)
            ->call('runBackup', 'inline-ui-passphrase')
            ->assertSet('statusVariant', 'success');

        // Public properties must not echo the secret back to the client.
        $component->assertNotSet('statusMessage', 'inline-ui-passphrase');

        $files = Storage::disk('local')->allFiles('backups');
        $artifacts = array_filter($files, fn ($f) => str_ends_with($f, '.bak.enc'));
        expect($artifacts)->toHaveCount(1);

        // Artifact begins with the passphrase magic header — confirms the
        // override drove a real encrypted run.
        $bytes = (string) Storage::disk('local')->get(array_values($artifacts)[0]);
        expect(substr($bytes, 0, 8))->toBe("BLBPASS\x01");
    } finally {
        DB::purge('backup_ui_source');
        @unlink($sourcePath);
    }
});

test('render reflects a retention keep_days override stored in base_settings', function (): void {
    $this->actingAs(createAdminUser());

    // Seed a global base_settings row that overrides the config-file default.
    DB::table('base_settings')->insert([
        'key' => 'backup.retention.keep_days',
        'value' => json_encode('99'),
        'is_encrypted' => false,
        'scope_type' => null,
        'scope_id' => null,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    // Config default is 30; the DB row should win.
    config()->set('backup.retention.keep_days', 30);

    Livewire::test(Index::class)
        ->assertSee('99');
});

test('saveField persists a backup setting override via SettingsService', function (): void {
    $this->actingAs(createAdminUser());

    Livewire::test(Index::class)
        ->call('saveField', 'backup.retention.keep_days', '14');

    expect(
        DB::table('base_settings')
            ->where('key', 'backup.retention.keep_days')
            ->whereNull('scope_type')
            ->value('value')
    )->toBe('"14"');
});

test('saveField ignores unknown field names', function (): void {
    $this->actingAs(createAdminUser());

    Livewire::test(Index::class)
        ->call('saveField', 'backup.internal_secret', 'evil');

    expect(
        DB::table('base_settings')->where('key', 'backup.internal_secret')->exists()
    )->toBeFalse();
});
