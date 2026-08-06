<?php

use App\Base\Foundation\ApplicationTopology;
use App\Base\Foundation\Compatibility\LegacyApplicationClassMap;
use Tests\TestCase;

uses(TestCase::class);

it('classifies paths against the four canonical roots with exact boundaries', function (): void {
    expect(ApplicationTopology::relativeRoots())->toBe([
        ApplicationTopology::BASE,
        ApplicationTopology::CORE,
        ApplicationTopology::DOMAINS,
        ApplicationTopology::EXTENSIONS,
    ])
        ->and(ApplicationTopology::rootFor('app/Core'))->toBe(ApplicationTopology::CORE)
        ->and(ApplicationTopology::rootFor('app\\Domains\\People\\Payroll'))
        ->toBe(ApplicationTopology::DOMAINS)
        ->and(ApplicationTopology::rootFor(base_path('app/Extensions/Ham/AutoParts')))
        ->toBe(ApplicationTopology::EXTENSIONS)
        ->and(ApplicationTopology::rootFor('app/CoreAdjacent/AI'))->toBeNull()
        ->and(ApplicationTopology::rootFor('app/Core/../Domains/People'))->toBeNull()
        ->and(ApplicationTopology::rootFor(dirname(base_path()).'/outside'))->toBeNull();
});

it('answers root membership and builds canonical relative paths', function (): void {
    expect(ApplicationTopology::belongsToRoot(
        'app/Extensions/SbGroup/Ibp',
        ApplicationTopology::EXTENSIONS,
    ))->toBeTrue()
        ->and(ApplicationTopology::belongsToRoot(
            'app/Domains/People',
            ApplicationTopology::EXTENSIONS,
        ))->toBeFalse()
        ->and(ApplicationTopology::relativePathUnder(
            ApplicationTopology::DOMAINS,
            'People',
            'Payroll',
        ))->toBe('app/Domains/People/Payroll');
});

it('rejects unknown roots and unsafe relative path segments', function (string $root, array $segments): void {
    expect(fn (): string => ApplicationTopology::relativePathUnder($root, ...$segments))
        ->toThrow(InvalidArgumentException::class);
})->with([
    'unknown root' => ['app/Modules', ['People']],
    'empty segment' => [ApplicationTopology::DOMAINS, ['']],
    'current-directory segment' => [ApplicationTopology::DOMAINS, ['.']],
    'parent segment' => [ApplicationTopology::DOMAINS, ['..']],
    'forward-slash segment' => [ApplicationTopology::DOMAINS, ['People/Payroll']],
    'backslash segment' => [ApplicationTopology::DOMAINS, ['People\\Payroll']],
]);

it('maps pre-cutover class identities to one canonical identity', function (): void {
    expect(LegacyApplicationClassMap::canonical(
        'App\\Modules\\Core\\Geonames\\Database\\Seeders\\CountrySeeder',
    ))->toBe('App\\Core\\Geonames\\Database\\Seeders\\CountrySeeder')
        ->and(LegacyApplicationClassMap::canonical(
            'App\\Modules\\People\\Payroll\\Database\\Seeders\\PayrollSeeder',
        ))->toBe('App\\Domains\\People\\Payroll\\Database\\Seeders\\PayrollSeeder')
        ->and(LegacyApplicationClassMap::canonical(
            'Extensions\\SbGroup\\Ibp\\Database\\Seeders\\IbpSeeder',
        ))->toBe('App\\Extensions\\SbGroup\\Ibp\\Database\\Seeders\\IbpSeeder')
        ->and(LegacyApplicationClassMap::equivalents(
            'App\\Core\\Geonames\\Database\\Seeders\\CountrySeeder',
        ))->toBe([
            'App\\Core\\Geonames\\Database\\Seeders\\CountrySeeder',
            'App\\Modules\\Core\\Geonames\\Database\\Seeders\\CountrySeeder',
        ]);
});

it('autoloads bounded pre-cutover aliases after canonical class lookup fails', function (): void {
    foreach ([
        'App\\Modules\\Core\\Geonames\\Database\\Seeders\\CountrySeeder' => 'App\\Core\\Geonames\\Database\\Seeders\\CountrySeeder',
        'App\\Modules\\People\\Payroll\\Contracts\\Intake\\PayrollContributionState' => 'App\\Domains\\People\\Payroll\\Contracts\\Intake\\PayrollContributionState',
        'Extensions\\Ham\\AutoParts\\ServiceProvider' => 'App\\Extensions\\Ham\\AutoParts\\ServiceProvider',
    ] as $legacyClass => $canonicalClass) {
        expect(class_exists($legacyClass))->toBeTrue()
            ->and(is_a($legacyClass, $canonicalClass, true))->toBeTrue();
    }
});
