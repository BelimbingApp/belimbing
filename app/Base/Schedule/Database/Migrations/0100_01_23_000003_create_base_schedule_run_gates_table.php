<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One durable row per (source, key) that every manual-run reservation
        // decision locks before touching base_schedule_runs — the row itself
        // carries no state, it exists only to give SELECT ... FOR UPDATE a
        // stable target so the active-row check, the stale/supersede
        // decision, and the queued-row insert become one serialized
        // operation instead of a read-then-write race (#407 review, luna).
        Schema::create('base_schedule_run_gates', function (Blueprint $table): void {
            $table->id();
            $table->string('source', 40);
            $table->string('key');
            $table->timestamps();

            $table->unique(['source', 'key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('base_schedule_run_gates');
    }
};
