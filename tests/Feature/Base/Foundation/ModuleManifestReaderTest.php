<?php

use App\Base\Foundation\ModuleManifest\ModuleManifestReader;

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

it('finds no unmet People-internal requirements when the full People domain is loaded', function (): void {
    $reader = new ModuleManifestReader([base_path('app/Modules/People')]);

    $manifests = $reader->all();
    $unmet = $reader->verifyRequiredModules($manifests);

    // Core/Company and Core/Employee aren't People sub-modules, so they
    // won't be in the scanned set yet. The verifier flags them; filter
    // to People-internal requirements which should all be satisfied.
    $peopleUnmet = array_values(array_filter(
        $unmet,
        fn (array $u): bool => str_starts_with($u['missing'], 'people/'),
    ));

    expect($peopleUnmet)->toBe([]);
})->skip(fn (): bool => ! is_dir(app_path('Modules/People')), 'People domain not installed');
