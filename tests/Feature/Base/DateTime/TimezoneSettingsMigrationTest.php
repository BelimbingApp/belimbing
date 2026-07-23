<?php

use App\Base\DateTime\Enums\TimezoneMode;
use App\Base\DateTime\Services\TimezoneSettings;
use App\Base\Settings\Contracts\SettingsService;
use App\Base\Settings\DTO\Scope;
use App\Base\Settings\Models\Setting;
use App\Modules\Core\Company\Models\Company;
use App\Modules\Core\Employee\Models\Employee;
use App\Modules\Core\User\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function timezoneKeyRenameMigration(): Migration
{
    return require app_path(
        'Base/Settings/Database/Migrations/0100_01_13_000001_rename_localization_timezone_setting.php',
    );
}

function timezoneUserScopeMigration(): Migration
{
    return require app_path(
        'Modules/Core/User/Database/Migrations/0200_01_20_000006_migrate_timezone_mode_to_user_scope.php',
    );
}

function timezoneCompanyScopeMigration(): Migration
{
    return require app_path(
        'Modules/Core/Company/Database/Migrations/0200_01_07_001002_migrate_localization_timezone_to_company_scope.php',
    );
}

it('renames company timezone rows without overwriting an existing new-key value', function (): void {
    Setting::query()->create([
        'key' => 'ui.timezone.default',
        'value' => 'Asia/Kuala_Lumpur',
        'scope_type' => 'company',
        'scope_id' => 1,
    ]);
    Setting::query()->create([
        'key' => TimezoneSettings::LOCALIZATION_TIMEZONE_KEY,
        'value' => 'Asia/Singapore',
        'scope_type' => 'company',
        'scope_id' => 1,
    ]);
    Setting::query()->create([
        'key' => 'ui.timezone.default',
        'value' => 'UTC',
    ]);

    timezoneKeyRenameMigration()->up();

    expect(Setting::query()->where('key', 'ui.timezone.default')->exists())->toBeFalse()
        ->and(Setting::query()
            ->where('key', TimezoneSettings::LOCALIZATION_TIMEZONE_KEY)
            ->where('scope_type', 'company')
            ->where('scope_id', 1)
            ->value('value'))
        ->toBe('Asia/Singapore')
        ->and(Setting::query()
            ->where('key', TimezoneSettings::LOCALIZATION_TIMEZONE_KEY)
            ->whereNull('scope_type')
            ->value('value'))
        ->toBe('UTC');
});

it('materializes a legacy global timezone without overwriting company values', function (): void {
    $firstCompany = Company::factory()->minimal()->create();
    $secondCompany = Company::factory()->minimal()->create();

    Setting::query()->create([
        'key' => TimezoneSettings::LOCALIZATION_TIMEZONE_KEY,
        'value' => 'Asia/Kuala_Lumpur',
    ]);
    Setting::query()->create([
        'key' => TimezoneSettings::LOCALIZATION_TIMEZONE_KEY,
        'value' => 'Asia/Tokyo',
        'scope_type' => 'company',
        'scope_id' => $firstCompany->id,
    ]);

    timezoneCompanyScopeMigration()->up();

    expect(Setting::query()
        ->where('key', TimezoneSettings::LOCALIZATION_TIMEZONE_KEY)
        ->where('scope_type', 'company')
        ->where('scope_id', $firstCompany->id)
        ->value('value'))
        ->toBe('Asia/Tokyo')
        ->and(Setting::query()
            ->where('key', TimezoneSettings::LOCALIZATION_TIMEZONE_KEY)
            ->where('scope_type', 'company')
            ->where('scope_id', $secondCompany->id)
            ->value('value'))
        ->toBe('Asia/Kuala_Lumpur')
        ->and(Setting::query()
            ->where('key', TimezoneSettings::LOCALIZATION_TIMEZONE_KEY)
            ->whereNull('scope_type')
            ->exists())
        ->toBeFalse();
});

it('materializes each users effective legacy timezone mode at user scope', function (): void {
    config(['settings.cache_ttl' => 0]);

    $company = Company::factory()->minimal()->create();
    $employeeWithOverride = Employee::factory()->create(['company_id' => $company->id]);
    $employeeWithCompanyFallback = Employee::factory()->create(['company_id' => $company->id]);
    $employeeWithDefault = Employee::factory()->create(['company_id' => $company->id]);

    $userWithEmployeeOverride = User::factory()->create([
        'company_id' => $company->id,
        'employee_id' => $employeeWithOverride->id,
    ]);
    $userWithCompanyFallback = User::factory()->create([
        'company_id' => $company->id,
        'employee_id' => $employeeWithCompanyFallback->id,
    ]);
    $userWithGlobalFallback = User::factory()->create([
        'company_id' => null,
        'employee_id' => null,
    ]);
    $userWithDefault = User::factory()->create([
        'company_id' => $company->id,
        'employee_id' => $employeeWithDefault->id,
    ]);

    Setting::query()->create([
        'key' => TimezoneSettings::MODE_KEY,
        'value' => TimezoneMode::LOCAL->value,
    ]);
    Setting::query()->create([
        'key' => TimezoneSettings::MODE_KEY,
        'value' => TimezoneMode::UTC->value,
        'scope_type' => 'company',
        'scope_id' => $company->id,
    ]);
    Setting::query()->create([
        'key' => TimezoneSettings::MODE_KEY,
        'value' => TimezoneMode::LOCAL->value,
        'scope_type' => 'employee',
        'scope_id' => $employeeWithOverride->id,
    ]);
    Setting::query()->create([
        'key' => TimezoneSettings::MODE_KEY,
        'value' => TimezoneMode::COMPANY->value,
        'scope_type' => 'employee',
        'scope_id' => $employeeWithDefault->id,
    ]);

    timezoneUserScopeMigration()->up();

    $settings = app(SettingsService::class);

    expect($settings->get(
        TimezoneSettings::MODE_KEY,
        scope: Scope::user($userWithEmployeeOverride->id, $company->id),
    ))->toBe(TimezoneMode::LOCAL->value)
        ->and($settings->get(
            TimezoneSettings::MODE_KEY,
            scope: Scope::user($userWithCompanyFallback->id, $company->id),
        ))->toBe(TimezoneMode::UTC->value)
        ->and($settings->get(
            TimezoneSettings::MODE_KEY,
            scope: Scope::user($userWithGlobalFallback->id),
        ))->toBe(TimezoneMode::LOCAL->value)
        ->and($settings->get(
            TimezoneSettings::MODE_KEY,
            scope: Scope::user($userWithDefault->id, $company->id),
        ))->toBe(TimezoneMode::COMPANY->value)
        ->and(Setting::query()
            ->where('key', TimezoneSettings::MODE_KEY)
            ->where('scope_type', '!=', 'user')
            ->exists())
        ->toBeFalse()
        ->and(Setting::query()
            ->where('key', TimezoneSettings::MODE_KEY)
            ->whereNull('scope_type')
            ->exists())
        ->toBeFalse();
});
