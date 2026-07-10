<?php

use App\Base\Database\Concerns\IncubatingSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    use IncubatingSchema;

    public function up(): void
    {
        Schema::create('base_schedule_runs', function (Blueprint $table): void {
            $table->id();
            $table->string('source', 40)->default('scheduler')->index();
            $table->string('key')->index();
            $table->string('name')->index();
            $table->string('expression', 64)->nullable();
            $table->string('status', 20)->index(); // running|succeeded|failed|skipped
            $table->timestamp('started_at')->index();
            $table->timestamp('finished_at')->nullable();
            $table->integer('exit_code')->nullable();
            $table->unsignedInteger('runtime_ms')->nullable();
            $table->text('output_excerpt')->nullable();
            $table->timestamps();

            $table->index(['source', 'key', 'started_at'], 'base_schedule_runs_task_started_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('base_schedule_runs');
    }
};
