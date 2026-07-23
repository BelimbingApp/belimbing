<?php

use App\Base\Database\Livewire\Backups\Index;
use App\Base\Settings\Contracts\SettingsService;
use App\Modules\Core\User\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

uses(RefreshDatabase::class);

const BACKUPS_TEST_DISK = 'local';
const BACKUPS_TEST_PREFIX = 'backups/local';
const BACKUPS_TEST_MANIFEST_SUFFIX = '.manifest.json';

beforeEach(function (): void {
    setupAuthzRoles();
    Storage::fake(BACKUPS_TEST_DISK);
    app(SettingsService::class)->set('backup.disk', BACKUPS_TEST_DISK);
    app(SettingsService::class)->set('backup.path_prefix', 'backups');
    app(SettingsService::class)->set('backup.encryption.mode', 'none');
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

test('authenticated user without admin.system.database-backup.list capability is denied', function (): void {
    setupAuthzRoles();

    // Build a user without core_admin (no roles attached).
    $user = User::factory()->create();
    $this->actingAs($user);

    $this->get(route('admin.system.database-backups.index'))
        ->assertForbidden();
});

test('verify action reports OK when manifest sha matches artifact', function (): void {
    $this->actingAs(createAdminUser());

    $disk = Storage::disk(BACKUPS_TEST_DISK);
    $artifactPath = BACKUPS_TEST_PREFIX.'/test.bak';
    $manifestPath = BACKUPS_TEST_PREFIX.'/test'.BACKUPS_TEST_MANIFEST_SUFFIX;
    $payload = "fake-backup-bytes\n";
    $disk->put($artifactPath, $payload);
    $disk->put($manifestPath, json_encode(makeBackupManifestPayload('bk-test', $artifactPath, $payload)));

    Livewire::test(Index::class)
        ->call('verify', $manifestPath)
        ->assertSet('statusVariant', 'success')
        ->assertSee('Integrity OK');
});

test('verify action flags failure when artifact bytes differ from manifest', function (): void {
    $this->actingAs(createAdminUser());

    $disk = Storage::disk(BACKUPS_TEST_DISK);
    $artifactPath = BACKUPS_TEST_PREFIX.'/tampered.bak';
    $manifestPath = BACKUPS_TEST_PREFIX.'/tampered'.BACKUPS_TEST_MANIFEST_SUFFIX;
    $artifactBytes = 'tampered-bytes';
    $disk->put($artifactPath, $artifactBytes);
    $disk->put($manifestPath, json_encode(makeBackupManifestPayload('bk-bad', $artifactPath, $artifactBytes, [
        'size_bytes' => 14,
        'sha256' => str_repeat('0', 64),
    ])));

    Livewire::test(Index::class)
        ->call('verify', $manifestPath)
        ->assertSet('statusVariant', 'danger')
        ->assertSee('Integrity FAILED');
});

test('delete action removes the manifest and artifact pair', function (): void {
    $this->actingAs(createAdminUser());

    $disk = Storage::disk(BACKUPS_TEST_DISK);
    $artifactPath = BACKUPS_TEST_PREFIX.'/will-be-deleted.bak';
    $manifestPath = BACKUPS_TEST_PREFIX.'/will-be-deleted'.BACKUPS_TEST_MANIFEST_SUFFIX;
    $artifactBytes = 'bytes';
    $disk->put($artifactPath, $artifactBytes);
    $disk->put($manifestPath, json_encode(makeBackupManifestPayload('bk-del', $artifactPath, $artifactBytes)));

    Livewire::test(Index::class)
        ->call('delete', $manifestPath)
        ->assertSet('statusVariant', 'success');

    expect($disk->exists($artifactPath))->toBeFalse();
    expect($disk->exists($manifestPath))->toBeFalse();
});

test('disabled backup config short-circuits the run-backup action', function (): void {
    $this->actingAs(createAdminUser());
    app(SettingsService::class)->set('backup.enabled', false);

    Livewire::test(Index::class)
        ->call('runBackup')
        ->assertSet('statusVariant', 'warning')
        ->assertSee('Backup is disabled');
});

test('runBackup flashes configuration error when encryption mode is not registered', function (): void {
    $this->actingAs(createAdminUser());

    $sourcePath = sys_get_temp_dir().'/blb-ui-unknown-mode-'.bin2hex(random_bytes(4)).'.sqlite';
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
    app(SettingsService::class)->set('backup.encryption.mode', 'ext-unregistered-mode-for-test');

    try {
        Livewire::test(Index::class)
            ->call('runBackup')
            ->assertSet('statusVariant', 'danger')
            ->assertSee('Unknown encryption mode');
    } finally {
        DB::purge('backup_ui_source');
        @unlink($sourcePath);
    }
});

test('runBackup completes in app-key mode and writes an encrypted artifact plus manifest', function (): void {
    if (! extension_loaded('sodium')) {
        $this->markTestSkipped('ext-sodium is required for app-key backup encryption.');
    }

    $this->actingAs(createAdminUser());

    $sourcePath = sys_get_temp_dir().'/blb-ui-appkey-'.bin2hex(random_bytes(4)).'.sqlite';
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
    app(SettingsService::class)->set('backup.encryption.mode', 'app-key');

    try {
        Livewire::test(Index::class)
            ->call('runBackup')
            ->assertSet('statusVariant', 'success');

        $files = Storage::disk(BACKUPS_TEST_DISK)->allFiles('backups');
        $artifacts = array_values(array_filter($files, fn ($f) => str_ends_with($f, '.bak.enc')));
        expect($artifacts)->toHaveCount(1);

        $manifestPath = str_replace('.bak.enc', BACKUPS_TEST_MANIFEST_SUFFIX, $artifacts[0]);
        expect(Storage::disk(BACKUPS_TEST_DISK)->exists($manifestPath))->toBeTrue();

        $manifest = json_decode((string) Storage::disk(BACKUPS_TEST_DISK)->get($manifestPath), true);
        expect(is_array($manifest))->toBeTrue()
            ->and($manifest['encryption_mode'] ?? null)->toBe('app-key');
    } finally {
        DB::purge('backup_ui_source');
        @unlink($sourcePath);
    }
});

test('render reflects a retention keep_days override stored in base_settings', function (): void {
    $this->actingAs(createAdminUser());

    app(SettingsService::class)->set('backup.retention.keep_days', 99);

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
    )->toBe('14');
});

test('saveField ignores unknown field names', function (): void {
    $this->actingAs(createAdminUser());

    Livewire::test(Index::class)
        ->call('saveField', 'backup.internal_secret', 'evil');

    expect(
        DB::table('base_settings')->where('key', 'backup.internal_secret')->exists()
    )->toBeFalse();
});

test('restoreSettingDefaults deletes backup overrides', function (): void {
    $this->actingAs(createAdminUser());
    $settings = app(SettingsService::class);
    $settings->set('backup.enabled', false);
    $settings->set('backup.retention.keep_days', 14);

    Livewire::test(Index::class)
        ->call('restoreSettingDefaults')
        ->assertSet('statusVariant', 'success');

    expect($settings->has('backup.enabled'))->toBeFalse()
        ->and($settings->has('backup.retention.keep_days'))->toBeFalse()
        ->and($settings->get('backup.enabled'))->toBeTrue()
        ->and($settings->get('backup.retention.keep_days'))->toBe(30);
});
