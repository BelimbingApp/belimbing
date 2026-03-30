<?php

use App\Base\DateTime\Contracts\DateTimeDisplayService;
use App\Base\DateTime\Enums\TimezoneMode;
use App\Base\Settings\Contracts\SettingsService;
use App\Base\Settings\DTO\Scope;
use App\Modules\Core\User\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, LazilyRefreshDatabase::class);

const DT_TEST_KEY_MODE = 'ui.timezone.mode';
const DT_TEST_KEY_DEFAULT = 'ui.timezone.default';
const DT_TEST_TIMEZONE_KL = 'Asia/Kuala_Lumpur';
const DT_TEST_TIMESTAMP = '2026-06-15 08:00:00';

beforeEach(function (): void {
    config(['settings.cache_ttl' => 0]);
    $this->settings = app(SettingsService::class);
});

/**
 * Build a fresh DateTimeDisplayService instance (clears memoized state).
 */
function freshService(): DateTimeDisplayService
{
    return app()->make(DateTimeDisplayService::class);
}

// --- Default Behavior (no auth, no settings) ---

it('defaults to COMPANY mode when unauthenticated', function (): void {
    expect(freshService()->currentMode())->toBe(TimezoneMode::COMPANY);
});

it('defaults to UTC timezone when unauthenticated in COMPANY mode', function (): void {
    expect(freshService()->currentTimezone())->toBe('UTC');
});

it('returns em-dash for null values', function (): void {
    $service = freshService();

    expect($service->formatDateTime(null))->toBe('—');
    expect($service->formatDate(null))->toBe('—');
    expect($service->formatTime(null))->toBe('—');
});

// --- Formatting in UTC Mode ---

it('formats datetime in UTC when mode is UTC', function (): void {
    $user = User::factory()->create(['company_id' => null]);
    $this->actingAs($user);

    $this->settings->set(DT_TEST_KEY_MODE, TimezoneMode::UTC->value);

    $service = freshService();
    $carbon = Carbon::parse(DT_TEST_TIMESTAMP, 'UTC');

    expect($service->formatDateTime($carbon))->toBe('2026-06-15 08:00');
    expect($service->formatDate($carbon))->toBe('2026-06-15');
    expect($service->formatTime($carbon))->toBe('08:00');
});

// --- Formatting in Company Mode with Timezone ---

it('formats datetime in company timezone', function (): void {
    $user = User::factory()->create(['company_id' => 1]);
    $this->actingAs($user);

    $this->settings->set(DT_TEST_KEY_DEFAULT, DT_TEST_TIMEZONE_KL, Scope::company(1));

    $service = freshService();
    $carbon = Carbon::parse(DT_TEST_TIMESTAMP, 'UTC');

    // KL is UTC+8
    expect($service->formatDateTime($carbon))->toBe('2026-06-15 16:00');
    expect($service->formatDate($carbon))->toBe('2026-06-15');
    expect($service->formatTime($carbon))->toBe('16:00');
});

// --- Local Mode Returns ISO-8601 ---

it('returns UTC ISO-8601 in local mode for browser formatting', function (): void {
    $user = User::factory()->create(['company_id' => null]);
    $this->actingAs($user);

    $this->settings->set(DT_TEST_KEY_MODE, TimezoneMode::LOCAL->value);

    $service = freshService();
    $carbon = Carbon::parse(DT_TEST_TIMESTAMP, 'UTC');

    $result = $service->formatDateTime($carbon);

    expect($result)->toContain('2026-06-15T08:00:00');
    expect($service->isLocalMode())->toBeTrue();
    expect($service->currentTimezone())->toBe('UTC');
});

// --- String Input ---

it('parses string datetime values', function (): void {
    $user = User::factory()->create(['company_id' => null]);
    $this->actingAs($user);

    $this->settings->set(DT_TEST_KEY_MODE, TimezoneMode::UTC->value);

    $service = freshService();

    expect($service->formatDateTime(DT_TEST_TIMESTAMP))->toBe('2026-06-15 08:00');
});

// --- Mode Resolution Cascade ---

it('resolves company-scoped mode override for user without employee', function (): void {
    $user = User::factory()->create(['company_id' => 1]);
    $this->actingAs($user);

    $this->settings->set(DT_TEST_KEY_MODE, TimezoneMode::UTC->value, Scope::company(1));

    expect(freshService()->currentMode())->toBe(TimezoneMode::UTC);
});

it('falls back to COMPANY for invalid mode value', function (): void {
    $user = User::factory()->create(['company_id' => null]);
    $this->actingAs($user);

    $this->settings->set(DT_TEST_KEY_MODE, 'invalid_mode');

    expect(freshService()->currentMode())->toBe(TimezoneMode::COMPANY);
});
