<?php

use App\Base\Audit\Livewire\AuditLog\OperatorActivity;
use App\Base\Audit\Services\AuditBuffer;
use App\Base\Database\Livewire\Backups\Index;
use App\Base\Database\Services\Backup\BackupService;
use App\Base\Settings\Contracts\SettingsService;
use App\Base\Settings\Support\SettingSubject;
use App\Base\Tenancy\Contracts\TenantContext;
use App\Core\User\Models\User;
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
        ->assertSee('History')
        ->assertSee('No backups yet');
});

test('backup history is bounded to the editable settings', function (): void {
    $this->actingAs(createAdminUser());

    Livewire::test(Index::class)
        ->assertViewHas('historySubjects', SettingSubject::handles([
            'backup.enabled',
            'backup.disk',
            'backup.path_prefix',
            'backup.encryption.mode',
            'backup.retention.keep_days',
            'backup.retention.keep_count',
        ]));
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

function backupsFlushAuditBuffer(): void
{
    $buffer = app(AuditBuffer::class);
    (new ReflectionClass($buffer))->getMethod('flush')->invoke($buffer);
}

/** @return list<object> */
function backupsAuditEvents(string $event): array
{
    backupsFlushAuditBuffer();

    return DB::table('base_audit_actions')->where('event', $event)->orderBy('id')->get()->all();
}

it('records database.backup.created with the manifest path and encryption mode when runBackup succeeds', function (): void {
    $this->actingAs(createAdminUser());

    $sourcePath = sys_get_temp_dir().'/blb-ui-audit-create-'.bin2hex(random_bytes(4)).'.sqlite';
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
    app(SettingsService::class)->set('backup.encryption.mode', 'none');

    try {
        Livewire::test(Index::class)->call('runBackup')->assertSet('statusVariant', 'success');

        $rows = backupsAuditEvents('database.backup.created');
        expect($rows)->toHaveCount(1);
        $payload = json_decode((string) $rows[0]->payload, true);
        expect($payload['semantic'] ?? null)->toBeTrue()
            ->and($payload['surface'] ?? null)->toBe('admin.system.database.backups')
            ->and($payload['context']['encryption_mode'] ?? null)->toBe('none')
            ->and($payload['subject']['identifier'] ?? null)->toContain('.manifest.json')
            ->and($payload['result'] ?? null)->toBe('succeeded');
    } finally {
        DB::purge('backup_ui_source');
        @unlink($sourcePath);
    }
});

it('records database.backup.deleted for both the manifest and artifact when delete succeeds', function (): void {
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

    $rows = backupsAuditEvents('database.backup.deleted');
    expect($rows)->toHaveCount(1);
    $payload = json_decode((string) $rows[0]->payload, true);
    expect($payload['context']['artifact_path'] ?? null)->toBe($artifactPath)
        ->and($payload['context']['manifest_path'] ?? null)->toBe($manifestPath)
        ->and($payload['result'] ?? null)->toBe('succeeded');
});

it('records database.backup.verify_failed with result failed when bytes differ', function (): void {
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
        ->assertSet('statusVariant', 'danger');

    $rows = backupsAuditEvents('database.backup.verify_failed');
    expect($rows)->toHaveCount(1);
    $payload = json_decode((string) $rows[0]->payload, true);
    expect($payload['result'] ?? null)->toBe('failed');
});

it('records nothing when the capability check refuses delete', function (): void {
    setupAuthzRoles();
    $user = User::factory()->create();
    $this->actingAs($user);

    $disk = Storage::disk(BACKUPS_TEST_DISK);
    $artifactPath = BACKUPS_TEST_PREFIX.'/denied.bak';
    $manifestPath = BACKUPS_TEST_PREFIX.'/denied'.BACKUPS_TEST_MANIFEST_SUFFIX;
    $artifactBytes = 'bytes';
    $disk->put($artifactPath, $artifactBytes);
    $disk->put($manifestPath, json_encode(makeBackupManifestPayload('bk-denied', $artifactPath, $artifactBytes)));

    Livewire::test(Index::class)
        ->call('delete', $manifestPath)
        ->assertForbidden();

    expect(backupsAuditEvents('database.backup.deleted'))->toBe([]);
    expect($disk->exists($manifestPath))->toBeTrue();
});

it('shows a backup deletion on Operator Activity under the ambient tenant and hides it from another tenant', function (): void {
    $user = createAdminUser();
    $homeTenant = app(TenantContext::class)->requireTenantId();
    $this->actingAs($user);

    $disk = Storage::disk(BACKUPS_TEST_DISK);
    $artifactPath = BACKUPS_TEST_PREFIX.'/op-act.bak';
    $manifestPath = BACKUPS_TEST_PREFIX.'/op-act'.BACKUPS_TEST_MANIFEST_SUFFIX;
    $artifactBytes = 'bytes';
    $disk->put($artifactPath, $artifactBytes);
    $disk->put($manifestPath, json_encode(makeBackupManifestPayload('bk-op', $artifactPath, $artifactBytes)));

    Livewire::test(Index::class)->call('delete', $manifestPath)->assertSet('statusVariant', 'success');
    backupsFlushAuditBuffer();

    expect(DB::table('base_audit_actions')->where('event', 'database.backup.deleted')->where('tenant_id', $homeTenant)->count())->toBe(1);

    Livewire::actingAs($user)
        ->test(OperatorActivity::class)
        ->assertSee('database.backup.deleted');

    [$foreignTenant] = createTenantWithCompany(['name' => 'Foreign Backup Audit Tenant']);
    app(TenantContext::class)->set((int) $foreignTenant->id);

    Livewire::actingAs($user)
        ->test(OperatorActivity::class)
        ->assertDontSee('database.backup.deleted');
});

it('records one database.backup.pruned row per expired artifact from blb:db:backup --prune', function (): void {
    $this->actingAs(createAdminUser());
    app(SettingsService::class)->set('backup.encryption.mode', 'none');
    app(SettingsService::class)->set('backup.retention.keep_days', 1);
    app(SettingsService::class)->set('backup.retention.keep_count', 0);

    $disk = Storage::disk(BACKUPS_TEST_DISK);
    $artifactPath = BACKUPS_TEST_PREFIX.'/expired.bak';
    $manifestPath = BACKUPS_TEST_PREFIX.'/expired'.BACKUPS_TEST_MANIFEST_SUFFIX;
    $artifactBytes = 'expired-bytes';
    $disk->put($artifactPath, $artifactBytes);
    $payload = makeBackupManifestPayload('bk-expired', $artifactPath, $artifactBytes, [
        'finished_at' => now()->subDays(10)->toIso8601String(),
    ]);
    $disk->put($manifestPath, json_encode($payload));

    $service = app(BackupService::class);
    $expired = [[
        'manifest_path' => $manifestPath,
        'artifact_path' => $artifactPath,
        'finished_at_unix' => now()->subDays(10)->timestamp,
    ]];

    $service->deleteEntries(
        diskName: BACKUPS_TEST_DISK,
        entries: $expired,
        surface: BackupService::SURFACE_CONSOLE,
        uiElement: '--prune',
        keepDays: 1,
    );

    $rows = backupsAuditEvents('database.backup.pruned');
    expect($rows)->toHaveCount(1);
    $rowPayload = json_decode((string) $rows[0]->payload, true);
    expect($rowPayload['surface'] ?? null)->toBe('console:blb:db:backup')
        ->and($rowPayload['context']['keep_days'] ?? null)->toBe(1)
        ->and($disk->exists($manifestPath))->toBeFalse();
});
