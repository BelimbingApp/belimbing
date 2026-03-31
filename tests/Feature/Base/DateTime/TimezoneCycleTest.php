<?php

use App\Base\DateTime\Enums\TimezoneMode;
use App\Base\Settings\Contracts\SettingsService;
use App\Base\Settings\DTO\Scope;
use App\Modules\Core\User\Models\User;

const TZ_SET_SETTINGS_KEY = 'ui.timezone.mode';

beforeEach(function (): void {
    config(['settings.cache_ttl' => 0]);
});

it('sets timezone mode to each valid value', function (string $mode): void {
    $user = User::factory()->create(['company_id' => 1]);

    $this->actingAs($user)
        ->postJson(route('timezone.set'), ['mode' => $mode])
        ->assertOk()
        ->assertJson(['mode' => $mode]);
})->with(['company', 'local', 'utc']);

it('returns timezone identifier for company and utc modes', function (): void {
    $user = User::factory()->create(['company_id' => 1]);
    $settings = app(SettingsService::class);
    $settings->set('ui.timezone.default', 'Asia/Kuala_Lumpur', Scope::company(1));

    $this->actingAs($user)
        ->postJson(route('timezone.set'), ['mode' => 'utc'])
        ->assertOk()
        ->assertJson([
            'timezone' => 'UTC',
            'company_timezone' => 'Asia/Kuala_Lumpur',
        ]);
});

it('returns null timezone for local mode', function (): void {
    $user = User::factory()->create(['company_id' => 1]);
    $settings = app(SettingsService::class);
    $settings->set('ui.timezone.default', 'Asia/Kuala_Lumpur', Scope::company(1));

    $response = $this->actingAs($user)
        ->postJson(route('timezone.set'), ['mode' => 'local'])
        ->assertOk();

    expect($response->json('timezone'))->toBeNull();
    expect($response->json('company_timezone'))->toBe('Asia/Kuala_Lumpur');
});

it('persists mode in settings at company scope', function (): void {
    $user = User::factory()->create(['company_id' => 1]);
    $settings = app(SettingsService::class);

    $this->actingAs($user)
        ->postJson(route('timezone.set'), ['mode' => 'local'])
        ->assertOk();

    $stored = $settings->get(TZ_SET_SETTINGS_KEY, null, Scope::company(1));

    expect($stored)->toBe(TimezoneMode::LOCAL->value);
});

it('rejects invalid mode values', function (): void {
    $user = User::factory()->create(['company_id' => 1]);

    $this->actingAs($user)
        ->postJson(route('timezone.set'), ['mode' => 'invalid'])
        ->assertUnprocessable();
});

it('requires authentication', function (): void {
    $this->postJson(route('timezone.set'), ['mode' => 'utc'])
        ->assertUnauthorized();
});
