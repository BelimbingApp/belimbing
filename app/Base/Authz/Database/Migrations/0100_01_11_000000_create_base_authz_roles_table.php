<?php
use App\Base\Authz\Database\Seeders\AuthzRoleSeeder;
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
        Schema::create('base_authz_roles', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('company_id')->nullable()->index();
            $table->string('name');
            $table->string('code');
            $table->text('description')->nullable();
            $table->boolean('is_system')->default(false);
            $table->boolean('grant_all')->default(false);
            $table->timestamps();

            $table->unique(['company_id', 'code']);
        });

        $this->registerSeeder(AuthzRoleSeeder::class);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('base_authz_roles');
        $this->unregisterSeeder(AuthzRoleSeeder::class);
    }
};
