<?php

use App\Base\Tenancy\Models\Tenant;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('tenants', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('parent_id')->nullable()->index();
            $table->string('name');
            $table->string('status')->default('active')->index(); // active, suspended, archived
            $table->timestamps();
            $table->softDeletes();

            $table->index(['parent_id', 'status']);
        });

        // Historical bootstrap only: this released migration predates the
        // explicit platform-operator marker and therefore seeded ID 1. The
        // additive marker migration preserves this row on upgrades but removes
        // the fresh-replay artifact before sequence-backed provisioning.
        DB::table('tenants')->updateOrInsert(
            ['id' => Tenant::LICENSEE_TENANT_ID],
            [
                'name' => config('app.licensee_company_name') ?: 'Licensee',
                'status' => 'active',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );

        // PostgreSQL sequences don't advance on explicit-ID inserts.
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement(
                "SELECT setval(pg_get_serial_sequence('tenants', 'id'), (SELECT COALESCE(MAX(id), 0) FROM tenants))"
            );
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tenants');
    }
};
