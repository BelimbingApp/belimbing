<?php

use Illuminate\Foundation\Testing\RefreshDatabase;

const USERS_TABLE_NAME = 'users';
const USERS_MIGRATION_FILE = '0200_01_20_000000_create_users_table.php';
const USERS_MIGRATION_PATH = 'app/Modules/Core/User/Database/Migrations/0200_01_20_000000_create_users_table.php';

uses(RefreshDatabase::class);

beforeEach(function (): void {
    setupAuthzRoles();
    $this->actingAs(createAdminUser());
});

test('database table show page renders schema tabs and migration source metadata', function (): void {
    $response = $this->get(route('admin.system.database-tables.show', USERS_TABLE_NAME));

    $response->assertOk()
        ->assertSee('Data')
        ->assertSee('Schema')
        ->assertSee('Relationships')
        ->assertSee('Migration source')
        ->assertSee(USERS_MIGRATION_FILE)
        ->assertSee(USERS_MIGRATION_PATH)
        ->assertSee("Schema::create('users'")
        ->assertSee('company_id')
        ->assertSee('email')
        ->assertSee('Indexes');
});

test('database table show page renders outgoing and incoming relationships', function (): void {
    $response = $this->get(route('admin.system.database-tables.show', USERS_TABLE_NAME));

    $response->assertOk()
        ->assertSee('Outgoing references')
        ->assertSee('Incoming references')
        ->assertSee('companies.id')
        ->assertSee('employees.id')
        ->assertSee('user_pins')
        ->assertSee('user_database_queries');
});

test('database table show page marks timestamp columns with shared timezone-aware rendering hooks', function (): void {
    $response = $this->get(route('admin.system.database-tables.show', USERS_TABLE_NAME));

    $response->assertOk()
        ->assertSee('data-company-timezone=', false)
        ->assertSee('data-raw-text=', false)
        ->assertSee('window.blbFormatDateTimeElement?.($el, { mode: tableTzMode })', false);
});
