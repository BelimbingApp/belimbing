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
            $table->string('source', 40)->default('scheduler');
            $table->string('key');
            $table->string('name');
            $table->timestamps();

            $table->unique(['source', 'key'], 'base_schedule_suppressions_source_key_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('base_schedule_suppressions');
    }
};
