<?php
namespace App\Modules\Core\Geonames\Database\Seeders;

use Illuminate\Database\Seeder;

class GeonamesSeeder extends Seeder
{
    /**
     * Run all Geonames-related seeders in order.
     */
    public function run(): void
    {
        $this->call([
            CountrySeeder::class,
            Admin1Seeder::class,
            PostcodeSeeder::class,
            CitySeeder::class,
        ]);
    }
}
