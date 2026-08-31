<?php

use App\Core\Company\Models\Company;
use App\Core\Employee\Models\Employee;
use App\Core\User\Models\User;
use Illuminate\Support\Facades\DB;

const AI_PROVIDER_BACKFILL_TEST_BASE_URL = 'https://example.invalid';

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
        'base_url' => AI_PROVIDER_BACKFILL_TEST_BASE_URL,
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
        'base_url' => AI_PROVIDER_BACKFILL_TEST_BASE_URL,
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
        'base_url' => AI_PROVIDER_BACKFILL_TEST_BASE_URL,
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

it('never backfills across a company boundary when a stale created_by numerically matches another company\'s employee', function (): void {
    // codex-gpt-5, #453: created_by was unconstrained (no FK), so a stale
    // Company A provider row can carry a created_by value that happens to
    // equal a Company B employee's id. Resolving purely by employee_id,
    // ignoring company, backfilled that provider's created_by_user_id to
    // the Company B user — a cross-tenant attribution. Reproduced directly:
    // company B's employee is deliberately given the same numeric id company
    // A's stale created_by value points at.
    $migration = aiProviderCreatedByMigration();
    $migration->down();

    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();

    $employeeB = Employee::factory()->create(['company_id' => $companyB->id]);
    User::factory()->create(['company_id' => $companyB->id, 'employee_id' => $employeeB->id]);

    // Company A has no employee at all — created_by is a dangling id that
    // only resolves to something if company is ignored.
    $providerId = DB::table('ai_providers')->insertGetId([
        'company_id' => $companyA->id,
        'name' => 'cross-company',
        'family' => 'llm',
        'display_name' => 'Cross Company',
        'base_url' => AI_PROVIDER_BACKFILL_TEST_BASE_URL,
        'auth_type' => 'api_key',
        'is_active' => true,
        'priority' => 0,
        'created_by' => $employeeB->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $migration->up();

    expect(DB::table('ai_providers')->where('id', $providerId)->value('created_by_user_id'))->toBeNull();
});

it('resolves a linked user whose own company_id is null through the employee fallback, within the provider\'s company', function (): void {
    // codex-gpt-5, #453: User::getCompanyId() supports users.company_id ===
    // null, falling back to the linked employee's company — a state
    // tests/Feature/Core/User/UserTest.php already protects. Requiring an
    // exact users.company_id match in the backfill join rejected that
    // supported state outright, losing resolvable attribution for a user
    // created exactly this way under the legacy write path.
    $migration = aiProviderCreatedByMigration();
    $migration->down();

    $company = Company::factory()->create();
    $employee = Employee::factory()->create(['company_id' => $company->id]);
    $user = User::factory()->create(['company_id' => null, 'employee_id' => $employee->id]);

    $providerId = DB::table('ai_providers')->insertGetId([
        'company_id' => $company->id,
        'name' => 'null-company-user',
        'family' => 'llm',
        'display_name' => 'Null Company User',
        'base_url' => AI_PROVIDER_BACKFILL_TEST_BASE_URL,
        'auth_type' => 'api_key',
        'is_active' => true,
        'priority' => 0,
        'created_by' => $employee->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $migration->up();

    expect(DB::table('ai_providers')->where('id', $providerId)->value('created_by_user_id'))->toBe($user->id);
});

it('still excludes a user whose own company_id genuinely differs from the provider\'s', function (): void {
    // The other half of the same join fix: null resolves through the
    // employee fallback, but a *non-null*, different company_id is the real
    // cross-company case and must still be excluded, not accidentally
    // accepted by a fix aimed at the null case.
    $migration = aiProviderCreatedByMigration();
    $migration->down();

    $company = Company::factory()->create();
    $otherCompany = Company::factory()->create();
    $employee = Employee::factory()->create(['company_id' => $company->id]);
    User::factory()->create(['company_id' => $otherCompany->id, 'employee_id' => $employee->id]);

    $providerId = DB::table('ai_providers')->insertGetId([
        'company_id' => $company->id,
        'name' => 'mismatched-company-user',
        'family' => 'llm',
        'display_name' => 'Mismatched Company User',
        'base_url' => AI_PROVIDER_BACKFILL_TEST_BASE_URL,
        'auth_type' => 'api_key',
        'is_active' => true,
        'priority' => 0,
        'created_by' => $employee->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $migration->up();

    expect(DB::table('ai_providers')->where('id', $providerId)->value('created_by_user_id'))->toBeNull();
});
