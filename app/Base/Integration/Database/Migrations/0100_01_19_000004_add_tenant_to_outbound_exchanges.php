<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('base_integration_outbound_exchanges', function (Blueprint $table): void {
            // Legacy ownership cannot be inferred safely. Null rows remain
            // available only to platform operators, never ordinary tenants.
            $table->unsignedBigInteger('tenant_id')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table('base_integration_outbound_exchanges', function (Blueprint $table): void {
            $table->dropColumn('tenant_id');
        });
    }
};
