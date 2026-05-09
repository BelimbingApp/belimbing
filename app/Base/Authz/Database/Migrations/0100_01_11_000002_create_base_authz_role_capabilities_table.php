<?php
use App\Base\Authz\Database\Seeders\AuthzRoleCapabilitySeeder;
use App\Base\Database\Concerns\RegistersSeeders;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    use RegistersSeeders;

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('base_authz_role_capabilities', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('role_id')->constrained('base_authz_roles')->cascadeOnDelete();
            $table->string('capability_key');
            $table->timestamps();

            $table->unique(['role_id', 'capability_key']);
            $table->index('capability_key');
        });

        $this->registerSeeder(AuthzRoleCapabilitySeeder::class);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('base_authz_role_capabilities');
        $this->unregisterSeeder(AuthzRoleCapabilitySeeder::class);
    }
};
