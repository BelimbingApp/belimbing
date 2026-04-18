<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

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
        Schema::create('base_authz_principal_roles', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('company_id')->nullable()->index();
            $table->string('principal_type', 40); // user | agent
            $table->unsignedBigInteger('principal_id');
            $table->foreignId('role_id')->constrained('base_authz_roles')->cascadeOnDelete();
            $table->timestamps();

            $table->index(['principal_type', 'principal_id']);
            $table->unique(['company_id', 'principal_type', 'principal_id', 'role_id'], 'base_authz_principal_roles_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('base_authz_principal_roles');
    }
};
