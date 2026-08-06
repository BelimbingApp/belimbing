<?php

use App\Base\Database\Models\SeederRegistry;
use App\Core\Geonames\Database\Seeders\CountrySeeder;

it('uses one canonical seeder identity across legacy rollback and rerun calls', function (): void {
    $canonicalClass = CountrySeeder::class;
    $legacyClass = 'App\\Modules\\Core\\Geonames\\Database\\Seeders\\CountrySeeder';

    SeederRegistry::query()->whereIn('seeder_class', [$canonicalClass, $legacyClass])->delete();
    SeederRegistry::query()->create([
        'seeder_class' => $canonicalClass,
        'module_name' => 'Geonames',
        'module_path' => 'app/Core/Geonames',
        'migration_file' => '0200_01_03_000000_create_geonames_countries_table.php',
        'status' => SeederRegistry::STATUS_COMPLETED,
        'ran_at' => now(),
    ]);

    SeederRegistry::unregister($legacyClass);

    expect(SeederRegistry::query()
        ->whereIn('seeder_class', [$canonicalClass, $legacyClass])
        ->count())->toBe(0);

    SeederRegistry::register(
        $legacyClass,
        'Geonames',
        'app/Core/Geonames',
        '0200_01_03_000000_create_geonames_countries_table.php',
    );
    SeederRegistry::register(
        $legacyClass,
        'Geonames',
        'app/Core/Geonames',
        '0200_01_03_000000_create_geonames_countries_table.php',
    );

    $registered = SeederRegistry::query()
        ->whereIn('seeder_class', [$canonicalClass, $legacyClass])
        ->get();

    expect($registered)->toHaveCount(1)
        ->and($registered->first()->seeder_class)->toBe($canonicalClass)
        ->and($registered->first()->status)->toBe(SeederRegistry::STATUS_PENDING)
        ->and(SeederRegistry::query()
            ->runnable()
            ->whereIn('seeder_class', [$canonicalClass, $legacyClass])
            ->count())->toBe(1);
});
