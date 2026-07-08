<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('base_schedule_runs', function (Blueprint $table): void {
            $table->id();
            $table->string('source', 40)->default('scheduler')->index();
            $table->string('name')->index();
            $table->string('status', 20)->index(); // running|succeeded|failed
            $table->timestamp('started_at')->index();
            $table->timestamp('finished_at')->nullable();
            $table->unsignedInteger('exit_code')->nullable();
            $table->text('output_excerpt')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('base_schedule_runs');
    }
};
