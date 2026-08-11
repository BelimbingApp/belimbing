<?php

use App\Base\DateTime\Enums\TimezoneMode;
use App\Base\DateTime\Services\TimezoneSettings;
use App\Base\Settings\Contracts\SettingsService;
use App\Base\Settings\DTO\Scope;
use App\Base\Settings\Models\Setting;
use App\Core\Company\Models\Company;
use App\Core\Employee\Models\Employee;
use App\Core\User\Models\User;
use Illuminate\Support\Js;

const TZ_SET_COMPANY_TIMEZONE_KL = 'Asia/Kuala_Lumpur';

beforeEach(function (): void {
    config(['settings.cache_ttl' => 0]);
});

it('sets timezone mode to each valid value', function (string $mode): void {
    $user = User::factory()->create(['company_id' => platformOperatorCompany()->id]);

    $this->actingAs($user)
        ->postJson(route('timezone.set'), ['mode' => $mode])
        ->assertOk()
        ->assertJson(['mode' => $mode]);
})->with(['company', 'local', 'utc']);

it('returns timezone identifier for company and utc modes', function (): void {
    $companyId = platformOperatorCompany()->id;
    $user = User::factory()->create(['company_id' => $companyId]);
    $settings = app(SettingsService::class);
    $settings->set(TimezoneSettings::LOCALIZATION_TIMEZONE_KEY, TZ_SET_COMPANY_TIMEZONE_KL, Scope::company($companyId));

    $this->actingAs($user)
        ->postJson(route('timezone.set'), ['mode' => 'utc'])
        ->assertOk()
        ->assertJson([
            'timezone' => 'UTC',
            'company_timezone' => TZ_SET_COMPANY_TIMEZONE_KL,
        ]);
});

it('returns null timezone for local mode', function (): void {
    $companyId = platformOperatorCompany()->id;
    $user = User::factory()->create(['company_id' => $companyId]);
    $settings = app(SettingsService::class);
    $settings->set(TimezoneSettings::LOCALIZATION_TIMEZONE_KEY, TZ_SET_COMPANY_TIMEZONE_KL, Scope::company($companyId));

    $response = $this->actingAs($user)
        ->postJson(route('timezone.set'), ['mode' => 'local'])
        ->assertOk();

    expect($response->json('timezone'))->toBeNull();
    expect($response->json('company_timezone'))->toBe(TZ_SET_COMPANY_TIMEZONE_KL);
});

it('persists mode in settings at user scope', function (): void {
    $companyId = platformOperatorCompany()->id;
    $user = User::factory()->create(['company_id' => $companyId]);
    $settings = app(SettingsService::class);

    $this->actingAs($user)
        ->postJson(route('timezone.set'), ['mode' => 'local'])
        ->assertOk();

    expect($settings->get(
        TimezoneSettings::MODE_KEY,
        scope: Scope::user($user->id, $companyId),
    ))->toBe(TimezoneMode::LOCAL->value)
        ->and(Setting::query()
            ->where('key', TimezoneSettings::MODE_KEY)
            ->where('scope_type', 'company')
            ->exists())
        ->toBeFalse();
});

it('persists mode against the user even when the account has an employee id', function (): void {
    $companyId = platformOperatorCompany()->id;
    $employee = Employee::factory()->create(['company_id' => $companyId]);
    $user = User::factory()->create([
        'company_id' => $companyId,
        'employee_id' => $employee->id,
    ]);
    $settings = app(SettingsService::class);

    $this->actingAs($user)
        ->postJson(route('timezone.set'), ['mode' => 'utc'])
        ->assertOk();

    expect($settings->get(TimezoneSettings::MODE_KEY, scope: Scope::user($user->id, $companyId)))
        ->toBe(TimezoneMode::UTC->value)
        ->and(Setting::query()
            ->where('key', TimezoneSettings::MODE_KEY)
            ->where('scope_type', 'employee')
            ->exists())
        ->toBeFalse();
});

it('routes an unset company timezone to the authenticated users company settings', function (): void {
    $company = Company::factory()->create();
    $user = User::factory()->create(['company_id' => $company->id]);

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSee('aria-label="Select timezone display mode"', false)
        ->assertSee((string) Js::from(route('admin.companies.show', $company)), false)
        ->assertDontSee((string) Js::from(route('admin.companies.show', platformOperatorCompany()->id)), false);
});

it('rejects invalid mode values', function (): void {
    $user = User::factory()->create(['company_id' => platformOperatorCompany()->id]);

    $this->actingAs($user)
        ->postJson(route('timezone.set'), ['mode' => 'invalid'])
        ->assertUnprocessable();
});

it('requires authentication', function (): void {
    $this->postJson(route('timezone.set'), ['mode' => 'utc'])
        ->assertRedirect(route('login'));
});
