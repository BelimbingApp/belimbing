<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('base_schedule_runs') && ! Schema::hasColumn('base_schedule_runs', 'attempt_key')) {
            Schema::table('base_schedule_runs', function (Blueprint $table) {
                $table->string('attempt_key', 36)->nullable()->index()->after('expression');
            });
        }

        if (Schema::hasTable('base_schedule_run_history') && ! Schema::hasColumn('base_schedule_run_history', 'attempt_key')) {
            Schema::table('base_schedule_run_history', function (Blueprint $table) {
                $table->string('attempt_key', 36)->nullable()->after('expression');
            });

            DB::table('base_schedule_run_history')
                ->whereNull('attempt_key')
                ->orderBy('id')
                ->get()
                ->each(function (object $row): void {
                    DB::table('base_schedule_run_history')
                        ->where('id', $row->id)
                        ->update(['attempt_key' => (string) Str::uuid()]);
                });

            Schema::table('base_schedule_run_history', function (Blueprint $table) {
                $table->unique('attempt_key', 'base_schedule_run_history_attempt_key_unique');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('base_schedule_run_history') && Schema::hasColumn('base_schedule_run_history', 'attempt_key')) {
            Schema::table('base_schedule_run_history', function (Blueprint $table) {
                $table->dropUnique('base_schedule_run_history_attempt_key_unique');
                $table->dropColumn('attempt_key');
            });
        }

        if (Schema::hasTable('base_schedule_runs') && Schema::hasColumn('base_schedule_runs', 'attempt_key')) {
            Schema::table('base_schedule_runs', function (Blueprint $table) {
                $table->dropColumn('attempt_key');
            });
        }
    }
};
