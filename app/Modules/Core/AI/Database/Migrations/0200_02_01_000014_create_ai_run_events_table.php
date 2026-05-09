<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Creates the append-only run event log. Each row is one immutable
     * event in the ordered stream a client subscribes to via SSE.
     *
     * `seq` is application-assigned per run, strictly increasing, and
     * enforced via unique constraint on (run_id, seq). The SSE resume
     * contract uses `after_seq` to replay missed events on reconnect.
     */
    public function up(): void
    {
        Schema::create('ai_run_events', function (Blueprint $table): void {
            $table->id();
            $table->ulid('run_id');
            $table->unsignedBigInteger('seq');
            $table->string('event_type', 60);
            $table->json('payload')->nullable();
            $table->timestamp('created_at');

            $table->unique(['run_id', 'seq']);
            $table->index('event_type');

            $table->foreign('run_id')
                ->references('id')
                ->on('ai_runs')
                ->cascadeOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ai_run_events');
    }
};
