<?php
use App\Base\Database\Concerns\RegistersSeeders;
use App\Modules\Core\Geonames\Database\Seeders\CountrySeeder;
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
        Schema::create('geonames_countries', function (Blueprint $table) {
            $table->id();
            $table->string('iso', 2)->unique()->index();
            $table->string('iso3', 3)->unique();
            $table->string('iso_numeric', 3)->unique();
            $table->string('country');
            $table->string('capital')->nullable();
            $table->float('area')->nullable();
            $table->unsignedBigInteger('population')->default(0);
            $table->string('continent', 2)->index();
            $table->string('tld', 3)->nullable();
            $table->string('currency_code', 3)->nullable();
            $table->string('currency_name', 32)->nullable();
            $table->string('phone', 24)->nullable();
            $table->string('postal_code_format', 100)->nullable();
            $table->text('postal_code_regex')->nullable();
            $table->string('languages')->nullable();
            $table->unsignedInteger('geoname_id')->nullable()->unique();
            $table->timestamps();
        });

        // Register seeder - module path auto-derived from migration file location
        $this->registerSeeder(CountrySeeder::class);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('geonames_countries');
        // Unregister seeder on rollback for clean state
        $this->unregisterSeeder(CountrySeeder::class);
    }
};
