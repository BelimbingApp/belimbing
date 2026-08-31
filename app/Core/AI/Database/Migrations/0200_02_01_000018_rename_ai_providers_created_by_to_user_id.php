<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `ai_providers.created_by` was unconstrained and typed as an employee id
 * (`AiProvider::createdBy()` pointed at `Employee`), the only column of that
 * name in `App\Core\AI` to mean that — `AiPricingOverride`/`ScheduleDefinition`
 * both already point `created_by`/`created_by_user_id` at `User`. Renaming
 * disambiguates the column; every write site moves to the always-present
 * acting user instead of the optional employee link.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->lockBackfillInputs();
        $assignments = $this->preflightAssignments();

        Schema::table('ai_providers', function (Blueprint $table): void {
            $table->foreignId('created_by_user_id')->nullable()->after('created_by')
                ->constrained('users')->nullOnDelete();
        });

        foreach ($assignments as $providerId => $userId) {
            DB::table('ai_providers')->where('id', $providerId)->update(['created_by_user_id' => $userId]);
        }

        Schema::table('ai_providers', function (Blueprint $table): void {
            $table->dropIndex(['created_by']);
            $table->dropColumn('created_by');
        });
    }

    public function down(): void
    {
        Schema::table('ai_providers', function (Blueprint $table): void {
            $table->unsignedBigInteger('created_by')->nullable()->index();
        });

        // The old column held employee ids; created_by_user_id holds user ids.
        // Converting back would need the same employee-lookup this migration
        // does forward, and any provider created after the rename has no
        // employee id to recover at all. Down is schema-only.
        Schema::table('ai_providers', function (Blueprint $table): void {
            $table->dropForeign(['created_by_user_id']);
            $table->dropColumn('created_by_user_id');
        });
    }

    /**
     * The old `created_by` values are employee ids (per `AiProvider::createdBy()`'s
     * former `belongsTo(Employee::class, ...)`). Translate each to that
     * employee's linked user where exactly one exists, within the
     * provider's own company.
     *
     * `created_by` was unconstrained (no FK), so a stale or corrupt value on
     * a Company A provider can numerically match a Company B employee id —
     * reviewed on this PR (codex-gpt-5): reproduced with two companies,
     * where resolving `created_by` globally backfilled a Company A
     * provider's `created_by_user_id` to a Company B user, a cross-tenant
     * attribution the rename must not create. Every candidate employee and
     * user is required to share the provider's own `company_id`.
     *
     * `users.employee_id` is nullable and foreign-keyed but not unique, so
     * more than one same-company user can point at the same employee
     * (steward review, #453): picking one via `pluck` — last row wins, no
     * ORDER BY — would silently assign attribution to an arbitrary user,
     * the same failure shape this migration exists to end. An ambiguous
     * employee id is left unresolved instead: the provider loses
     * attribution the same way a fresh NULL write already would, and up()
     * reports the count so it is not a silent guess.
     *
     * @return array<int, int> provider id => user id
     */
    private function preflightAssignments(): array
    {
        $providers = DB::table('ai_providers')
            ->whereNotNull('created_by')
            ->get(['id', 'company_id', 'created_by']);

        if ($providers->isEmpty()) {
            return [];
        }

        $userCountsByProvider = DB::table('ai_providers')
            ->join('employees', function ($join): void {
                $join->on('employees.id', '=', 'ai_providers.created_by')
                    ->on('employees.company_id', '=', 'ai_providers.company_id');
            })
            ->join('users', function ($join): void {
                // User::getCompanyId() supports users.company_id === null,
                // falling back to the linked employee's company (protected
                // by tests/Feature/Core/User/UserTest.php) — reviewed on
                // this PR (codex-gpt-5): requiring an exact company_id
                // match here rejected that supported state and lost
                // resolvable attribution for it. The employee join above
                // already proves the employee's company matches the
                // provider's, so a null users.company_id resolves through
                // that fallback; only a *different*, non-null company_id
                // is the real cross-company case this still excludes.
                $join->on('users.employee_id', '=', 'employees.id')
                    ->where(function ($company): void {
                        $company->whereColumn('users.company_id', 'ai_providers.company_id')
                            ->orWhereNull('users.company_id');
                    });
            })
            ->whereNotNull('ai_providers.created_by')
            ->selectRaw('ai_providers.id as provider_id, count(*) as user_count')
            ->groupBy('ai_providers.id')
            ->pluck('user_count', 'provider_id');

        $singleUserIdsByProvider = DB::table('ai_providers')
            ->join('employees', function ($join): void {
                $join->on('employees.id', '=', 'ai_providers.created_by')
                    ->on('employees.company_id', '=', 'ai_providers.company_id');
            })
            ->join('users', function ($join): void {
                // User::getCompanyId() supports users.company_id === null,
                // falling back to the linked employee's company (protected
                // by tests/Feature/Core/User/UserTest.php) — reviewed on
                // this PR (codex-gpt-5): requiring an exact company_id
                // match here rejected that supported state and lost
                // resolvable attribution for it. The employee join above
                // already proves the employee's company matches the
                // provider's, so a null users.company_id resolves through
                // that fallback; only a *different*, non-null company_id
                // is the real cross-company case this still excludes.
                $join->on('users.employee_id', '=', 'employees.id')
                    ->where(function ($company): void {
                        $company->whereColumn('users.company_id', 'ai_providers.company_id')
                            ->orWhereNull('users.company_id');
                    });
            })
            ->whereIn('ai_providers.id', $userCountsByProvider->filter(fn (int $count): bool => $count === 1)->keys())
            ->selectRaw('ai_providers.id as provider_id, users.id as user_id')
            ->pluck('user_id', 'provider_id');

        $assignments = [];
        $ambiguous = 0;

        foreach ($providers as $provider) {
            $providerId = (int) $provider->id;
            $userId = $singleUserIdsByProvider->get($providerId);

            if ($userId !== null) {
                $assignments[$providerId] = (int) $userId;
            } elseif (($userCountsByProvider->get($providerId) ?? 0) > 1) {
                $ambiguous++;
            }
        }

        if ($ambiguous > 0) {
            fwrite(STDERR, "ai_providers.created_by backfill: {$ambiguous} provider(s) had a creator employee linked to more than one same-company user; left created_by_user_id NULL for those rather than guessing.\n");
        }

        return $assignments;
    }

    /**
     * The preflight snapshot (provider rows, candidate counts, candidate
     * ids) is several separate SELECTs, not one atomic read — reviewed on
     * this PR (codex-gpt-5): without a lock, a provider insert/update, or
     * an employee/user affiliation change, committed between those reads
     * can be missed or go stale before `created_by` is dropped. Matches the
     * lock this package already takes for the same reason elsewhere (e.g.
     * `0200_01_05_000002_add_tenant_to_addresses.php`).
     */
    private function lockBackfillInputs(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('LOCK TABLE ai_providers, employees, users IN SHARE ROW EXCLUSIVE MODE');
    }
};
