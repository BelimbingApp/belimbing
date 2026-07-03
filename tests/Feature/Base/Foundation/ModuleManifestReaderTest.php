<?php

use App\Base\Foundation\ModuleManifest\ModuleManifestReader;
use App\Base\Foundation\Services\DomainState;
use Illuminate\Support\Facades\File;

const MODULE_MANIFEST_ROOT_PREFIX = 'framework/testing/module-manifests-';
const MODULE_MANIFEST_COMPOSER_JSON = '/composer.json';
const MODULE_MANIFEST_EXTENSIONS_SEGMENT = '/extensions/';
const ACME_PAYROLL_MODULE = 'acme/payroll';
const ACME_DEPENDENT_MODULE = 'acme/dependent';
const ACME_REQUIRED_MODULE = 'acme/required';
const MODULE_MANIFEST_TEST_VERSION = '1.0.0';
const REQUIRED_MODULE_SUFFIX = '/required';
const DISABLED_MANIFEST_PROVIDER = 'zz-disabled-for-manifest-test/provider';

it('reads extra.blb metadata from People sub-module composer.json files', function (): void {
    $reader = new ModuleManifestReader([base_path('app/Modules/People')]);

    $manifests = $reader->all();

    $names = array_map(fn ($m) => $m->name, $manifests);

    expect($names)->toContain(
        'blb/people-attendance',
        'blb/people-leave',
        'blb/people-claim',
        'blb/people-settings',
        'blb/payroll',
    );

    $attendance = collect($manifests)->firstWhere('name', 'blb/people-attendance');

    expect($attendance->version)->not->toBe('')
        ->and($attendance->description)->not->toBe('')
        ->and($attendance->publishesEvents)->toContain(
            'App\\Modules\\People\\Attendance\\Events\\AttendanceOvertimeApproved',
            'App\\Modules\\People\\Attendance\\Events\\AttendanceAllowanceMaterialized',
        );

    $payroll = collect($manifests)->firstWhere('name', 'blb/payroll');

    expect($payroll->version)->not->toBe('')
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
    $root = storage_path(MODULE_MANIFEST_ROOT_PREFIX.bin2hex(random_bytes(4)));
    $module = $root.MODULE_MANIFEST_EXTENSIONS_SEGMENT.ACME_PAYROLL_MODULE;

    File::ensureDirectoryExists($module);

    file_put_contents($module.MODULE_MANIFEST_COMPOSER_JSON, json_encode([
        'name' => ACME_PAYROLL_MODULE,
        'extra' => [
            'blb' => [
                'module' => ACME_PAYROLL_MODULE,
                'version' => '1.2.3',
            ],
        ],
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

    try {
        $manifests = (new ModuleManifestReader([$root.'/extensions']))->all();

        expect($manifests)->toHaveCount(1)
            ->and($manifests[0]->name)->toBe(ACME_PAYROLL_MODULE)
            ->and($manifests[0]->module)->toBe(ACME_PAYROLL_MODULE)
            ->and($manifests[0]->version)->toBe('1.2.3');
    } finally {
        File::deleteDirectory($root);
    }
});

it('uses manifest module identity instead of also accepting the conventional path identity', function (): void {
    $root = storage_path(MODULE_MANIFEST_ROOT_PREFIX.bin2hex(random_bytes(4)));
    $canonical = $root.MODULE_MANIFEST_EXTENSIONS_SEGMENT.ACME_PAYROLL_MODULE;
    $dependent = $root.MODULE_MANIFEST_EXTENSIONS_SEGMENT.ACME_DEPENDENT_MODULE;

    File::ensureDirectoryExists($canonical);
    File::ensureDirectoryExists($dependent);

    file_put_contents($canonical.MODULE_MANIFEST_COMPOSER_JSON, json_encode([
        'name' => ACME_PAYROLL_MODULE,
        'extra' => ['blb' => ['module' => 'vendor/payroll', 'version' => MODULE_MANIFEST_TEST_VERSION]],
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

    file_put_contents($dependent.MODULE_MANIFEST_COMPOSER_JSON, json_encode([
        'name' => ACME_DEPENDENT_MODULE,
        'extra' => ['blb' => [
            'module' => ACME_DEPENDENT_MODULE,
            'version' => MODULE_MANIFEST_TEST_VERSION,
            'requires-modules' => [ACME_PAYROLL_MODULE => '*'],
        ]],
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

    try {
        $reader = new ModuleManifestReader([$root.'/extensions']);

        expect($reader->moduleRoots())->toHaveKey('vendor/payroll')
            ->not->toHaveKey(ACME_PAYROLL_MODULE)
            ->and($reader->verifyRequiredModules($reader->all()))->toBe([
                ['requiring' => ACME_DEPENDENT_MODULE, 'missing' => ACME_PAYROLL_MODULE],
            ]);
    } finally {
        File::deleteDirectory($root);
    }
});

it('uses the filesystem identity and manifest version when the module id is omitted', function (): void {
    $owner = 'zz-manifest-conventional-'.bin2hex(random_bytes(4));
    $root = base_path(trim(MODULE_MANIFEST_EXTENSIONS_SEGMENT, '/').'/'.$owner);
    $required = $root.REQUIRED_MODULE_SUFFIX;
    $dependent = $root.'/dependent';

    foreach ([$required, $dependent] as $module) {
        File::ensureDirectoryExists($module);
    }

    file_put_contents($required.MODULE_MANIFEST_COMPOSER_JSON, json_encode([
        'name' => $owner.REQUIRED_MODULE_SUFFIX,
        'extra' => ['blb' => ['version' => '1.2.0']],
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

    file_put_contents($dependent.MODULE_MANIFEST_COMPOSER_JSON, json_encode([
        'name' => $owner.'/dependent',
        'extra' => ['blb' => [
            'version' => MODULE_MANIFEST_TEST_VERSION,
            'requires-modules' => [$owner.REQUIRED_MODULE_SUFFIX => '^1.0'],
        ]],
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

    try {
        $reader = new ModuleManifestReader([$root]);
        $manifests = collect($reader->all())->keyBy('name');

        expect($manifests[$owner.REQUIRED_MODULE_SUFFIX]->module)->toBe($owner.REQUIRED_MODULE_SUFFIX)
            ->and($reader->moduleRoots())->toHaveKey($owner.REQUIRED_MODULE_SUFFIX)
            ->and($reader->dependencyIssues($reader->all()))->toBe([]);
    } finally {
        File::deleteDirectory($root);
    }
});

it('reports incompatible required module versions', function (): void {
    $root = storage_path(MODULE_MANIFEST_ROOT_PREFIX.bin2hex(random_bytes(4)));

    foreach ([
        'Required' => [
            'name' => ACME_REQUIRED_MODULE,
            'extra' => ['blb' => ['module' => ACME_REQUIRED_MODULE, 'version' => MODULE_MANIFEST_TEST_VERSION]],
        ],
        'Dependent' => [
            'name' => ACME_DEPENDENT_MODULE,
            'extra' => ['blb' => [
                'module' => ACME_DEPENDENT_MODULE,
                'version' => MODULE_MANIFEST_TEST_VERSION,
                'requires-modules' => [ACME_REQUIRED_MODULE => '^2.0.0'],
            ]],
        ],
    ] as $directory => $payload) {
        File::ensureDirectoryExists($root.'/'.$directory);
        file_put_contents($root.'/'.$directory.MODULE_MANIFEST_COMPOSER_JSON, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    try {
        $reader = new ModuleManifestReader([$root]);
        $issues = $reader->dependencyIssues($reader->all());

        expect($issues)->toHaveCount(1)
            ->and($issues[0]['issue'])->toBe('incompatible')
            ->and($issues[0]['required'])->toBe(ACME_REQUIRED_MODULE)
            ->and($issues[0]['constraint'])->toBe('^2.0.0')
            ->and($issues[0]['installed_version'])->toBe(MODULE_MANIFEST_TEST_VERSION);
    } finally {
        File::deleteDirectory($root);
    }
});

it('accepts common composer-style required module version constraints', function (): void {
    $root = storage_path(MODULE_MANIFEST_ROOT_PREFIX.bin2hex(random_bytes(4)));

    $manifests = [
        'Required' => [
            'name' => ACME_REQUIRED_MODULE,
            'extra' => ['blb' => ['module' => ACME_REQUIRED_MODULE, 'version' => '2.1.3']],
        ],
    ];

    foreach ([
        'A' => ['suffix' => 'a', 'constraint' => '>= 2.0 < 3.0'],
        'B' => ['suffix' => 'b', 'constraint' => '^1.0 || ^2.0'],
        'C' => ['suffix' => 'c', 'constraint' => '~2.1'],
        'D' => ['suffix' => 'd', 'constraint' => '2.*'],
    ] as $key => $case) {
        $module = 'acme/dependent-'.$case['suffix'];
        $manifests['Dependent'.$key] = [
            'name' => $module,
            'extra' => ['blb' => [
                'module' => $module,
                'requires-modules' => [ACME_REQUIRED_MODULE => $case['constraint']],
            ]],
        ];
    }

    foreach ($manifests as $directory => $payload) {
        File::ensureDirectoryExists($root.'/'.$directory);
        file_put_contents($root.'/'.$directory.MODULE_MANIFEST_COMPOSER_JSON, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
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

    file_put_contents($disabledDomain.'/Provider/composer.json', json_encode([
        'name' => 'test/disabled-provider',
        'extra' => ['blb' => [
            'module' => DISABLED_MANIFEST_PROVIDER,
            'version' => MODULE_MANIFEST_TEST_VERSION,
        ]],
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

    file_put_contents($enabledDomain.'/Consumer/composer.json', json_encode([
        'name' => 'test/enabled-consumer',
        'extra' => ['blb' => [
            'module' => 'zz-enabled-for-manifest-test/consumer',
            'requires-modules' => [DISABLED_MANIFEST_PROVIDER => '*'],
        ]],
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

    try {
        $reader = new ModuleManifestReader([app_path('Modules')]);

        expect(collect($reader->all())->pluck('module')->all())->not->toContain(DISABLED_MANIFEST_PROVIDER)
            ->and(collect($reader->allIncludingDisabledDomains())->pluck('module')->all())->toContain(DISABLED_MANIFEST_PROVIDER)
            ->and($reader->moduleRoots())->not->toHaveKey(DISABLED_MANIFEST_PROVIDER)
            ->and($reader->verifyRequiredModules($reader->all()))->toContain([
                'requiring' => 'test/enabled-consumer',
                'missing' => DISABLED_MANIFEST_PROVIDER,
            ]);
    } finally {
        File::deleteDirectory($disabledDomain);
        File::deleteDirectory($enabledDomain);
        DomainState::useStatePath(null);
        @unlink($statePath);
    }
});
