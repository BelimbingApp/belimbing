<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('base_schedule_runs', function (Blueprint $table): void {
            // 'scheduled' (the cron ticker) vs 'manual' (an operator's Run now
            // click) — kept distinct from `source`, which identifies *what*
            // ran, not *how* it was started (#401).
            $table->string('trigger', 20)->default('scheduled')->after('source');

            // No FK constraint: Base must not depend on Core's users table.
            $table->unsignedBigInteger('triggered_by_user_id')->nullable()->after('trigger')->index();

            // Denormalized display name, captured from the acting user at
            // dispatch time — avoids a Base-to-Core\User model dependency
            // just to show who clicked "Run now" in History.
            $table->string('triggered_by_name', 255)->nullable()->after('triggered_by_user_id');
        });
    }

    public function down(): void
    {
        Schema::table('base_schedule_runs', function (Blueprint $table): void {
            $table->dropColumn(['trigger', 'triggered_by_user_id', 'triggered_by_name']);
        });
    }
};
