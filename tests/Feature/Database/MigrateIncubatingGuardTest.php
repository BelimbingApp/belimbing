<?php

use App\Base\Database\Services\IncubatingSchemaApprovalRepository;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

const MIGRATE_GUARD_DIR = 'extensions/test-vendor/guard-mod/Database/Migrations';
const MIGRATE_GUARD_FILE = '2099_01_01_010101_create_test_guard_widgets_table.php';
const MIGRATE_GUARD_TABLE = 'test_guard_widgets';
const MIGRATE_GUARD_FAIL_FILE = '2099_01_01_010102_create_test_guard_failing_widgets_table.php';
const MIGRATE_GUARD_FAIL_TABLE = 'test_guard_failing_widgets';

function writeFailingMigrateGuardMigration(string $relativeDir, string $file, string $table): string
{
    $dir = base_path($relativeDir);

    if (! is_dir($dir)) {
        mkdir($dir, 0755, true);
    }

    $path = $dir.'/'.$file;

    file_put_contents($path, <<<PHP
    <?php
    use App\\Base\\Database\\Concerns\\IncubatingSchema;
    use Illuminate\\Database\\Migrations\\Migration;
    use Illuminate\\Support\\Facades\\Schema;

    return new class extends Migration
    {
        use IncubatingSchema;

        public function up(): void
        {
            throw new RuntimeException('intentional incubating migration failure');
        }

        public function down(): void
        {
            Schema::dropIfExists('{$table}');
        }
    };
    PHP);

    return $path;
}

beforeEach(function (): void {
    putenv('BLB_INCUBATING_SCHEMA_APPROVALS='.storage_path('framework/testing/incubating-schema-approvals.json'));
    @unlink(app(IncubatingSchemaApprovalRepository::class)->path());

    $bootstrapAdmin = storage_path('framework/testing/migrate-guard-admin.txt');
    if (! is_dir(dirname($bootstrapAdmin))) {
        mkdir(dirname($bootstrapAdmin), 0755, true);
    }
    file_put_contents($bootstrapAdmin, "Migration Guard Admin\nadmin@example.test\npassword\n");
    putenv('BLB_BOOTSTRAP_ADMIN_FILE='.$bootstrapAdmin);
});

afterEach(function (): void {
    cleanupIncubatingTestMigration(MIGRATE_GUARD_DIR, MIGRATE_GUARD_FILE, MIGRATE_GUARD_TABLE);
    cleanupIncubatingTestMigration(MIGRATE_GUARD_DIR, MIGRATE_GUARD_FAIL_FILE, MIGRATE_GUARD_FAIL_TABLE);
    @unlink(app(IncubatingSchemaApprovalRepository::class)->path());
    @unlink((string) getenv('BLB_BOOTSTRAP_ADMIN_FILE'));
    putenv('BLB_BOOTSTRAP_ADMIN_FILE');
    putenv('BLB_INCUBATING_SCHEMA_APPROVALS');
});

test('plain migrate blocks pending incubating schema on a non-disposable database', function (): void {
    writeIncubatingTestMigration(MIGRATE_GUARD_DIR, MIGRATE_GUARD_FILE, MIGRATE_GUARD_TABLE);

    app()['env'] = 'production';

    $this->artisan('migrate', ['--force' => true])
        ->expectsOutputToContain('Pending incubating schema cannot be migrated outside local/testing without a local approval')
        ->assertExitCode(1);

    expect(Schema::hasTable(MIGRATE_GUARD_TABLE))->toBeFalse();
});

test('plain migrate allows applied incubating schema and records a source baseline', function (): void {
    $path = writeIncubatingTestMigration(MIGRATE_GUARD_DIR, MIGRATE_GUARD_FILE, MIGRATE_GUARD_TABLE);
    Schema::create(MIGRATE_GUARD_TABLE, function (Blueprint $table): void {
        $table->id();
    });
    DB::table('migrations')->insert([
        'migration' => str_replace('.php', '', MIGRATE_GUARD_FILE),
        'batch' => 1,
    ]);

    app()['env'] = 'production';

    $this->artisan('migrate', ['--force' => true])
        ->expectsOutputToContain('Applied incubating schema remains source-declared')
        ->assertExitCode(0);

    expect(DB::table('base_database_migration_sources')
        ->where('migration_name', str_replace('.php', '', MIGRATE_GUARD_FILE))
        ->value('source_sha256'))->toBe(hash_file('sha256', $path));
});

test('plain migrate blocks applied incubating schema when its source hash changed', function (): void {
    writeIncubatingTestMigration(MIGRATE_GUARD_DIR, MIGRATE_GUARD_FILE, MIGRATE_GUARD_TABLE);
    Schema::create(MIGRATE_GUARD_TABLE, function (Blueprint $table): void {
        $table->id();
    });
    DB::table('migrations')->insert([
        'migration' => str_replace('.php', '', MIGRATE_GUARD_FILE),
        'batch' => 1,
    ]);
    DB::table('base_database_migration_sources')->insert([
        'migration_name' => str_replace('.php', '', MIGRATE_GUARD_FILE),
        'migration_file' => MIGRATE_GUARD_FILE,
        'relative_path' => MIGRATE_GUARD_DIR.'/'.MIGRATE_GUARD_FILE,
        'source_sha256' => str_repeat('0', 64),
        'source_state' => 'incubating',
        'first_observed_at' => now()->utc(),
        'last_observed_at' => now()->utc(),
        'created_at' => now()->utc(),
        'updated_at' => now()->utc(),
    ]);

    app()['env'] = 'production';

    $this->artisan('migrate', ['--force' => true])
        ->expectsOutputToContain('Applied incubating schema source has changed since this database baseline')
        ->assertExitCode(1);
});

test('plain migrate blocks baselined incubating schema when its source marker is removed', function (): void {
    $path = writeIncubatingTestMigration(MIGRATE_GUARD_DIR, MIGRATE_GUARD_FILE, MIGRATE_GUARD_TABLE);
    Schema::create(MIGRATE_GUARD_TABLE, function (Blueprint $table): void {
        $table->id();
    });
    DB::table('migrations')->insert([
        'migration' => str_replace('.php', '', MIGRATE_GUARD_FILE),
        'batch' => 1,
    ]);
    DB::table('base_database_migration_sources')->insert([
        'migration_name' => str_replace('.php', '', MIGRATE_GUARD_FILE),
        'migration_file' => MIGRATE_GUARD_FILE,
        'relative_path' => MIGRATE_GUARD_DIR.'/'.MIGRATE_GUARD_FILE,
        'source_sha256' => hash_file('sha256', $path),
        'source_state' => 'incubating',
        'first_observed_at' => now()->utc(),
        'last_observed_at' => now()->utc(),
        'created_at' => now()->utc(),
        'updated_at' => now()->utc(),
    ]);

    $table = MIGRATE_GUARD_TABLE;

    file_put_contents($path, <<<PHP
    <?php
    use Illuminate\\Database\\Migrations\\Migration;
    use Illuminate\\Database\\Schema\\Blueprint;
    use Illuminate\\Support\\Facades\\Schema;

    return new class extends Migration
    {
        public function up(): void
        {
            Schema::create('{$table}', function (Blueprint \$t): void {
                \$t->id();
            });
        }

        public function down(): void
        {
            Schema::dropIfExists('{$table}');
        }
    };
    PHP);

    app()['env'] = 'production';

    $this->artisan('migrate', ['--force' => true])
        ->expectsOutputToContain('Applied incubating schema source has changed since this database baseline')
        ->assertExitCode(1);
});

test('plain migrate runs a pending incubating migration with exact local approval', function (): void {
    writeIncubatingTestMigration(MIGRATE_GUARD_DIR, MIGRATE_GUARD_FILE, MIGRATE_GUARD_TABLE);

    app()['env'] = 'production';

    $this->artisan('blb:schema:approve-incubating', [
        'migration' => str_replace('.php', '', MIGRATE_GUARD_FILE),
        '--backup' => 'backup-before-live-schema-test',
        '--reason' => 'exercise production-only integration path',
    ])->assertExitCode(0);

    $this->artisan('migrate', ['--force' => true])
        ->expectsOutputToContain('Approved pending incubating schema will run on this non-disposable database')
        ->assertExitCode(0);

    $approval = json_decode((string) file_get_contents(app(IncubatingSchemaApprovalRepository::class)->path()), true);

    expect(Schema::hasTable(MIGRATE_GUARD_TABLE))->toBeTrue()
        ->and(DB::table('base_database_migration_sources')
            ->where('migration_name', str_replace('.php', '', MIGRATE_GUARD_FILE))
            ->exists())->toBeTrue()
        ->and($approval['approvals'][0]['consumed_at'] ?? null)->toBeString();
});

test('graceful failed approved incubating migration leaves approval unconsumed', function (): void {
    writeFailingMigrateGuardMigration(MIGRATE_GUARD_DIR, MIGRATE_GUARD_FAIL_FILE, MIGRATE_GUARD_FAIL_TABLE);

    app()['env'] = 'production';

    $this->artisan('blb:schema:approve-incubating', [
        'migration' => str_replace('.php', '', MIGRATE_GUARD_FAIL_FILE),
        '--backup' => 'backup-before-failing-schema-test',
        '--reason' => 'exercise graceful migration failure',
    ])->assertExitCode(0);

    $this->artisan('migrate', ['--force' => true, '--graceful' => true])
        ->assertExitCode(0);

    $approval = json_decode((string) file_get_contents(app(IncubatingSchemaApprovalRepository::class)->path()), true);

    expect(Schema::hasTable(MIGRATE_GUARD_FAIL_TABLE))->toBeFalse()
        ->and(DB::table('migrations')->where('migration', str_replace('.php', '', MIGRATE_GUARD_FAIL_FILE))->exists())->toBeFalse()
        ->and($approval['approvals'][0]['consumed_at'] ?? null)->toBeNull();
});

test('incubating approval can be bound to a named sqlite connection', function (): void {
    writeIncubatingTestMigration(MIGRATE_GUARD_DIR, MIGRATE_GUARD_FILE, MIGRATE_GUARD_TABLE);
    $sqlitePath = storage_path('framework/testing/incubating-approval.sqlite');
    $missingDefaultPath = storage_path('framework/testing/missing-default.sqlite');
    $defaultConnection = config('database.default');

    if (! is_dir(dirname($sqlitePath))) {
        mkdir(dirname($sqlitePath), 0755, true);
    }

    touch($sqlitePath);

    config()->set('database.connections.incubating_guard_sqlite', [
        'driver' => 'sqlite',
        'database' => $sqlitePath,
        'prefix' => '',
        'foreign_key_constraints' => true,
    ]);
    config()->set('database.connections.incubating_guard_broken_default', [
        'driver' => 'sqlite',
        'database' => $missingDefaultPath,
        'prefix' => '',
        'foreign_key_constraints' => true,
    ]);

    try {
        config()->set('database.default', 'incubating_guard_broken_default');

        $this->artisan('blb:schema:approve-incubating', [
            'migration' => str_replace('.php', '', MIGRATE_GUARD_FILE),
            '--database' => 'incubating_guard_sqlite',
            '--backup' => 'sqlite-file-copy-before-live-schema-test',
            '--reason' => 'exercise sqlite production approval binding',
        ])->assertExitCode(0);

        $approval = json_decode((string) file_get_contents(app(IncubatingSchemaApprovalRepository::class)->path()), true);

        expect($approval['approvals'][0]['connection'] ?? null)->toBe('incubating_guard_sqlite')
            ->and($approval['approvals'][0]['driver'] ?? null)->toBe('sqlite')
            ->and($approval['approvals'][0]['database'] ?? null)->toBe(realpath($sqlitePath) ?: $sqlitePath);
    } finally {
        config()->set('database.default', $defaultConnection);
        DB::purge('incubating_guard_sqlite');
        DB::purge('incubating_guard_broken_default');
        @unlink($sqlitePath);
    }
});

test('plain migrate applies incubating schema on a disposable testing database', function (): void {
    writeIncubatingTestMigration(MIGRATE_GUARD_DIR, MIGRATE_GUARD_FILE, MIGRATE_GUARD_TABLE);

    $this->artisan('migrate', ['--force' => true])->assertExitCode(0);

    expect(Schema::hasTable(MIGRATE_GUARD_TABLE))->toBeTrue();
});
