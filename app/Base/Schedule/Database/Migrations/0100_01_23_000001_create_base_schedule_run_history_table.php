<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('base_schedule_run_history', function (Blueprint $table) {
            $table->id();
            $table->string('command_key', 191)->index();
            $table->string('command', 255);
            $table->string('expression', 64)->nullable();
            $table->string('attempt_key', 36)->unique();
            $table->string('status', 32)->index();
            $table->integer('exit_code')->nullable();
            $table->integer('runtime_ms')->nullable();
            $table->text('output')->nullable();
            $table->timestamp('started_at')->nullable()->index();
            $table->timestamp('finished_at')->nullable()->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('base_schedule_run_history');
    }
};
