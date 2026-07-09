<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('base_schedule_suppressions', function (Blueprint $table): void {
            $table->id();
            $table->string('name')->unique(); // recorder-normalized command name
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('base_schedule_suppressions');
    }
};
