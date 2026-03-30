<?php

use App\Base\DateTime\Enums\TimezoneMode;
use App\Base\Settings\Contracts\SettingsService;
use App\Base\Settings\DTO\Scope;
use App\Modules\Core\User\Models\User;
const TZ_CYCLE_SETTINGS_KEY = 'ui.timezone.mode';

beforeEach(function (): void {
    config(['settings.cache_ttl' => 0]);
});

it('cycles through Company → Local → UTC → Company', function (): void {
    $user = User::factory()->create(['company_id' => 1]);
    $settings = app(SettingsService::class);

    // Default is Company → cycles to Local
    $this->actingAs($user)
        ->postJson(route('timezone.cycle'))
        ->assertOk()
        ->assertJson(['mode' => 'local']);

    // Local → UTC
    $this->actingAs($user)
        ->postJson(route('timezone.cycle'))
        ->assertOk()
        ->assertJson(['mode' => 'utc']);

    // UTC → Company
    $this->actingAs($user)
        ->postJson(route('timezone.cycle'))
        ->assertOk()
        ->assertJson(['mode' => 'company']);
});

it('persists mode in settings at company scope', function (): void {
    $user = User::factory()->create(['company_id' => 1]);
    $settings = app(SettingsService::class);

    $this->actingAs($user)
        ->postJson(route('timezone.cycle'))
        ->assertOk();

    $stored = $settings->get(TZ_CYCLE_SETTINGS_KEY, null, Scope::company(1));

    expect($stored)->toBe(TimezoneMode::LOCAL->value);
});

it('requires authentication', function (): void {
    $this->postJson(route('timezone.cycle'))
        ->assertUnauthorized();
});
