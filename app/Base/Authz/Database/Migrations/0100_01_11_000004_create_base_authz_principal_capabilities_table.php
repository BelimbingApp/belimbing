<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('base_authz_principal_capabilities', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('company_id')->nullable()->index();
            $table->string('principal_type', 40);
            $table->unsignedBigInteger('principal_id');
            $table->string('capability_key');
            $table->boolean('is_allowed')->default(true);
            $table->timestamps();

            $table->index(['principal_type', 'principal_id'], 'base_authz_principal_caps_principal_index');
            $table->index('capability_key');
            $table->unique(
                ['company_id', 'principal_type', 'principal_id', 'capability_key'],
                'base_authz_principal_caps_unique'
            );
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('base_authz_principal_capabilities');
    }
};
