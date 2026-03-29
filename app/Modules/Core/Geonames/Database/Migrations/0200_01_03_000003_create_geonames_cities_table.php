<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

use App\Base\Database\Concerns\RegistersSeeders;
use App\Modules\Core\Geonames\Database\Seeders\CitySeeder;
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
        Schema::create('geonames_cities', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('geoname_id')->unique();
            $table->string('name', 200);
            $table->string('ascii_name', 200);
            $table->text('alternate_names')->nullable();
            $table->decimal('latitude', 10, 7);
            $table->decimal('longitude', 10, 7);
            $table->string('country_iso', 2)->index();
            $table->string('admin1_code', 20)->nullable()->index();
            $table->unsignedBigInteger('population')->default(0);
            $table->string('timezone', 40)->index();
            $table->date('modification_date')->nullable();
            $table->timestamps();

            $table
                ->foreign('country_iso')
                ->references('iso')
                ->on('geonames_countries')
                ->cascadeOnUpdate()
                ->restrictOnDelete();
        });

        $this->registerSeeder(CitySeeder::class);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $this->unregisterSeeder(CitySeeder::class);
        Schema::dropIfExists('geonames_cities');
    }
};
