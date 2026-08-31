<?php

use App\Core\Company\Models\Company;
use App\Core\Employee\Models\Employee;
use App\Core\User\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Exercises the 0200_02_01_000018 migration's backfill directly, restoring
 * the pre-migration schema, seeding data, and re-running it — the schema is
 * already migrated by the time RefreshDatabase hands control to a test, so
 * this is the only way to observe up() against a chosen starting state.
 *
 * Steward review on #453 caught the defect this guards: the first cut keyed
 * a pluck by employee_id, so two users sharing one employee_id (allowed —
 * users.employee_id is nullable and foreign-keyed but not unique) silently
 * picked whichever row the database happened to return last.
 */
function aiProviderCreatedByMigration(): object
{
    return require app_path('Core/AI/Database/Migrations/0200_02_01_000018_rename_ai_providers_created_by_to_user_id.php');
}

it('leaves created_by_user_id null when an employee has more than one linked user, and resolves the unambiguous ones', function (): void {
    $migration = aiProviderCreatedByMigration();
    $migration->down();

    $company = Company::factory()->create();

    $ambiguousEmployee = Employee::factory()->create(['company_id' => $company->id]);
    $firstUser = User::factory()->create(['company_id' => $company->id, 'employee_id' => $ambiguousEmployee->id]);
    $secondUser = User::factory()->create(['company_id' => $company->id, 'employee_id' => $ambiguousEmployee->id]);

    $unambiguousEmployee = Employee::factory()->create(['company_id' => $company->id]);
    $onlyUser = User::factory()->create(['company_id' => $company->id, 'employee_id' => $unambiguousEmployee->id]);

    $orphanedEmployee = Employee::factory()->create(['company_id' => $company->id]);

    $ambiguousProviderId = DB::table('ai_providers')->insertGetId([
        'company_id' => $company->id,
        'name' => 'ambiguous',
        'family' => 'llm',
        'display_name' => 'Ambiguous',
        'base_url' => 'https://example.invalid',
        'auth_type' => 'api_key',
        'is_active' => true,
        'priority' => 0,
        'created_by' => $ambiguousEmployee->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $unambiguousProviderId = DB::table('ai_providers')->insertGetId([
        'company_id' => $company->id,
        'name' => 'unambiguous',
        'family' => 'llm',
        'display_name' => 'Unambiguous',
        'base_url' => 'https://example.invalid',
        'auth_type' => 'api_key',
        'is_active' => true,
        'priority' => 0,
        'created_by' => $unambiguousEmployee->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $orphanedProviderId = DB::table('ai_providers')->insertGetId([
        'company_id' => $company->id,
        'name' => 'orphaned',
        'family' => 'llm',
        'display_name' => 'Orphaned',
        'base_url' => 'https://example.invalid',
        'auth_type' => 'api_key',
        'is_active' => true,
        'priority' => 0,
        'created_by' => $orphanedEmployee->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $migration->up();

    $resolved = DB::table('ai_providers')->pluck('created_by_user_id', 'id');

    expect($resolved->get($ambiguousProviderId))->toBeNull()
        ->and($resolved->get($unambiguousProviderId))->toBe($onlyUser->id)
        ->and($resolved->get($orphanedProviderId))->toBeNull();

    // The candidates were real, distinct users — the point being tested is
    // that neither was picked, not that they don't exist.
    expect($firstUser->id)->not->toBe($secondUser->id);
});
