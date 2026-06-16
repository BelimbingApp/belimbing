<?php

use Illuminate\Support\Facades\Schema;

const MIGRATE_GUARD_DIR = 'extensions/test-vendor/guard-mod/Database/Migrations';
const MIGRATE_GUARD_FILE = '2099_01_01_010101_create_test_guard_widgets_table.php';
const MIGRATE_GUARD_TABLE = 'test_guard_widgets';

afterEach(function (): void {
    cleanupIncubatingTestMigration(MIGRATE_GUARD_DIR, MIGRATE_GUARD_FILE, MIGRATE_GUARD_TABLE);
});

test('plain migrate blocks incubating schema on a non-disposable database', function (): void {
    writeIncubatingTestMigration(MIGRATE_GUARD_DIR, MIGRATE_GUARD_FILE, MIGRATE_GUARD_TABLE);

    app()['env'] = 'production';

    $this->artisan('migrate', ['--force' => true])
        ->expectsOutputToContain('Incubating schema cannot be migrated outside local/testing')
        ->assertExitCode(1);

    expect(Schema::hasTable(MIGRATE_GUARD_TABLE))->toBeFalse();
});

test('plain migrate applies incubating schema on a disposable testing database', function (): void {
    writeIncubatingTestMigration(MIGRATE_GUARD_DIR, MIGRATE_GUARD_FILE, MIGRATE_GUARD_TABLE);

    $this->artisan('migrate', ['--force' => true])->assertExitCode(0);

    expect(Schema::hasTable(MIGRATE_GUARD_TABLE))->toBeTrue();
});
