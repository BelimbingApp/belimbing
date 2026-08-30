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
     * employee's linked user where one exists; a provider whose creator has
     * no linked user loses attribution the same way a fresh NULL write would
     * have — not a new loss, the one this migration exists to stop causing
     * going forward.
     *
     * @return array<int, int> provider id => user id
     */
    private function preflightAssignments(): array
    {
        $providers = DB::table('ai_providers')
            ->whereNotNull('created_by')
            ->pluck('created_by', 'id');

        if ($providers->isEmpty()) {
            return [];
        }

        $userIdsByEmployeeId = DB::table('users')
            ->whereIn('employee_id', $providers->unique()->values())
            ->whereNotNull('employee_id')
            ->pluck('id', 'employee_id');

        $assignments = [];

        foreach ($providers as $providerId => $employeeId) {
            $userId = $userIdsByEmployeeId->get($employeeId);

            if ($userId !== null) {
                $assignments[(int) $providerId] = (int) $userId;
            }
        }

        return $assignments;
    }
};
