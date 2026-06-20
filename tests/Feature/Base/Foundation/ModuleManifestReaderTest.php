<?php

use App\Base\Foundation\ModuleManifest\ModuleManifestReader;
use App\Base\Foundation\Services\DomainState;
use Illuminate\Support\Facades\File;

it('reads extra.blb metadata from People sub-module composer.json files', function (): void {
    $reader = new ModuleManifestReader([base_path('app/Modules/People')]);

    $manifests = $reader->all();

    $names = array_map(fn ($m) => $m->name, $manifests);

    expect($names)->toContain(
        'blb/people-attendance',
        'blb/people-leave',
        'blb/people-claim',
        'blb/people-settings',
        'blb/payroll-my',
    );

    $attendance = collect($manifests)->firstWhere('name', 'blb/people-attendance');

    expect($attendance->role)->toBe('source')
        ->and($attendance->version)->not->toBe('')
        ->and($attendance->description)->not->toBe('')
        ->and($attendance->publishesEvents)->toContain(
            'App\\Modules\\People\\Attendance\\Events\\AttendanceOvertimeApproved',
            'App\\Modules\\People\\Attendance\\Events\\AttendanceAllowanceMaterialized',
        );

    $payroll = collect($manifests)->firstWhere('name', 'blb/payroll-my');

    expect($payroll->role)->toBe('plugin')
        ->and($payroll->version)->not->toBe('')
        ->and($payroll->description)->not->toBe('')
        ->and($payroll->consumesEvents)->toContain(
            'App\\Modules\\People\\Attendance\\Events\\AttendanceOvertimeApproved',
            'App\\Modules\\People\\Attendance\\Events\\AttendanceAllowanceMaterialized',
        );
})->skip(fn (): bool => ! is_dir(app_path('Modules/People')), 'People domain not installed');

it('treats conventional app module paths as installed required modules', function (): void {
    $reader = new ModuleManifestReader([base_path('app/Modules')]);

    $manifests = $reader->all();
    $unmet = $reader->verifyRequiredModules($manifests);

    expect($unmet)->toBe([]);
})->skip(fn (): bool => ! is_dir(app_path('Modules/People')), 'People domain not installed');

it('reads extension manifests from owner and module depth', function (): void {
    $root = storage_path('framework/testing/module-manifests-'.bin2hex(random_bytes(4)));
    $module = $root.'/extensions/acme/payroll';

    File::ensureDirectoryExists($module);

    file_put_contents($module.'/composer.json', json_encode([
        'name' => 'acme/payroll',
        'extra' => [
            'blb' => [
                'module' => 'acme/payroll',
                'role' => 'plugin',
                'version' => '1.2.3',
            ],
        ],
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

    try {
        $manifests = (new ModuleManifestReader([$root.'/extensions']))->all();

        expect($manifests)->toHaveCount(1)
            ->and($manifests[0]->name)->toBe('acme/payroll')
            ->and($manifests[0]->module)->toBe('acme/payroll')
            ->and($manifests[0]->version)->toBe('1.2.3');
    } finally {
        File::deleteDirectory($root);
    }
});

it('uses manifest module identity instead of also accepting the conventional path identity', function (): void {
    $root = storage_path('framework/testing/module-manifests-'.bin2hex(random_bytes(4)));
    $canonical = $root.'/extensions/acme/payroll';
    $dependent = $root.'/extensions/acme/dependent';

    File::ensureDirectoryExists($canonical);
    File::ensureDirectoryExists($dependent);

    file_put_contents($canonical.'/composer.json', json_encode([
        'name' => 'acme/payroll',
        'extra' => ['blb' => ['module' => 'vendor/payroll', 'role' => 'plugin', 'version' => '1.0.0']],
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

    file_put_contents($dependent.'/composer.json', json_encode([
        'name' => 'acme/dependent',
        'extra' => ['blb' => [
            'module' => 'acme/dependent',
            'role' => 'plugin',
            'version' => '1.0.0',
            'requires-modules' => ['acme/payroll' => '*'],
        ]],
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

    try {
        $reader = new ModuleManifestReader([$root.'/extensions']);

        expect($reader->moduleRoots())->toHaveKey('vendor/payroll')
            ->not->toHaveKey('acme/payroll')
            ->and($reader->verifyRequiredModules($reader->all()))->toBe([
                ['requiring' => 'acme/dependent', 'missing' => 'acme/payroll'],
            ]);
    } finally {
        File::deleteDirectory($root);
    }
});

it('uses the filesystem identity and manifest version when the module id is omitted', function (): void {
    $owner = 'zz-manifest-conventional-'.bin2hex(random_bytes(4));
    $root = base_path('extensions/'.$owner);
    $required = $root.'/required';
    $dependent = $root.'/dependent';

    foreach ([$required, $dependent] as $module) {
        File::ensureDirectoryExists($module);
    }

    file_put_contents($required.'/composer.json', json_encode([
        'name' => $owner.'/required',
        'extra' => ['blb' => ['role' => 'source', 'version' => '1.2.0']],
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

    file_put_contents($dependent.'/composer.json', json_encode([
        'name' => $owner.'/dependent',
        'extra' => ['blb' => [
            'role' => 'plugin',
            'version' => '1.0.0',
            'requires-modules' => [$owner.'/required' => '^1.0'],
        ]],
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

    try {
        $reader = new ModuleManifestReader([$root]);
        $manifests = collect($reader->all())->keyBy('name');

        expect($manifests[$owner.'/required']->module)->toBe($owner.'/required')
            ->and($reader->moduleRoots())->toHaveKey($owner.'/required')
            ->and($reader->dependencyIssues($reader->all()))->toBe([]);
    } finally {
        File::deleteDirectory($root);
    }
});

it('reports incompatible required module versions', function (): void {
    $root = storage_path('framework/testing/module-manifests-'.bin2hex(random_bytes(4)));

    foreach ([
        'Required' => [
            'name' => 'acme/required',
            'extra' => ['blb' => ['module' => 'acme/required', 'role' => 'source', 'version' => '1.0.0']],
        ],
        'Dependent' => [
            'name' => 'acme/dependent',
            'extra' => ['blb' => [
                'module' => 'acme/dependent',
                'role' => 'plugin',
                'version' => '1.0.0',
                'requires-modules' => ['acme/required' => '^2.0.0'],
            ]],
        ],
    ] as $directory => $payload) {
        File::ensureDirectoryExists($root.'/'.$directory);
        file_put_contents($root.'/'.$directory.'/composer.json', json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    try {
        $reader = new ModuleManifestReader([$root]);
        $issues = $reader->dependencyIssues($reader->all());

        expect($issues)->toHaveCount(1)
            ->and($issues[0]['issue'])->toBe('incompatible')
            ->and($issues[0]['required'])->toBe('acme/required')
            ->and($issues[0]['constraint'])->toBe('^2.0.0')
            ->and($issues[0]['installed_version'])->toBe('1.0.0');
    } finally {
        File::deleteDirectory($root);
    }
});

it('accepts common composer-style required module version constraints', function (): void {
    $root = storage_path('framework/testing/module-manifests-'.bin2hex(random_bytes(4)));

    $manifests = [
        'Required' => [
            'name' => 'acme/required',
            'extra' => ['blb' => ['module' => 'acme/required', 'role' => 'source', 'version' => '2.1.3']],
        ],
        'DependentA' => [
            'name' => 'acme/dependent-a',
            'extra' => ['blb' => ['module' => 'acme/dependent-a', 'role' => 'plugin', 'requires-modules' => ['acme/required' => '>= 2.0 < 3.0']]],
        ],
        'DependentB' => [
            'name' => 'acme/dependent-b',
            'extra' => ['blb' => ['module' => 'acme/dependent-b', 'role' => 'plugin', 'requires-modules' => ['acme/required' => '^1.0 || ^2.0']]],
        ],
        'DependentC' => [
            'name' => 'acme/dependent-c',
            'extra' => ['blb' => ['module' => 'acme/dependent-c', 'role' => 'plugin', 'requires-modules' => ['acme/required' => '~2.1']]],
        ],
        'DependentD' => [
            'name' => 'acme/dependent-d',
            'extra' => ['blb' => ['module' => 'acme/dependent-d', 'role' => 'plugin', 'requires-modules' => ['acme/required' => '2.*']]],
        ],
    ];

    foreach ($manifests as $directory => $payload) {
        File::ensureDirectoryExists($root.'/'.$directory);
        file_put_contents($root.'/'.$directory.'/composer.json', json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    try {
        $reader = new ModuleManifestReader([$root]);

        expect($reader->dependencyIssues($reader->all()))->toBe([]);
    } finally {
        File::deleteDirectory($root);
    }
});

it('does not count disabled domains as installed dependencies', function (): void {
    $statePath = storage_path('framework/testing/disabled-domains-'.bin2hex(random_bytes(4)).'.json');
    $disabledDomain = app_path('Modules/ZzDisabledForManifestTest');
    $enabledDomain = app_path('Modules/ZzEnabledForManifestTest');

    DomainState::useStatePath($statePath);
    DomainState::disable('ZzDisabledForManifestTest');

    File::ensureDirectoryExists($disabledDomain.'/Provider/Database/Migrations');
    File::ensureDirectoryExists($enabledDomain.'/Consumer');

    file_put_contents($enabledDomain.'/Consumer/composer.json', json_encode([
        'name' => 'test/enabled-consumer',
        'extra' => ['blb' => [
            'module' => 'zz-enabled-for-manifest-test/consumer',
            'role' => 'plugin',
            'requires-modules' => ['zz-disabled-for-manifest-test/provider' => '*'],
        ]],
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

    try {
        $reader = new ModuleManifestReader([app_path('Modules')]);

        expect($reader->moduleRoots())->not->toHaveKey('zz-disabled-for-manifest-test/provider')
            ->and($reader->verifyRequiredModules($reader->all()))->toContain([
                'requiring' => 'test/enabled-consumer',
                'missing' => 'zz-disabled-for-manifest-test/provider',
            ]);
    } finally {
        File::deleteDirectory($disabledDomain);
        File::deleteDirectory($enabledDomain);
        DomainState::useStatePath(null);
        @unlink($statePath);
    }
});
