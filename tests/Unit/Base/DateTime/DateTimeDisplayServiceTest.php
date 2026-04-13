<?php

use App\Base\DateTime\Contracts\DateTimeDisplayService;
use App\Base\DateTime\Enums\TimezoneMode;
use App\Base\Locale\Contracts\LocaleContext;
use App\Base\Settings\Contracts\SettingsService;
use App\Base\Settings\DTO\Scope;
use App\Modules\Core\User\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, LazilyRefreshDatabase::class);

const DT_TEST_KEY_MODE = 'ui.timezone.mode';
const DT_TEST_KEY_DEFAULT = 'ui.timezone.default';
const DT_TEST_KEY_LOCALE = 'ui.locale';
const DT_TEST_TIMEZONE_KL = 'Asia/Kuala_Lumpur';
const DT_TEST_TIMESTAMP = '2026-06-15 08:00:00';

beforeEach(function (): void {
    config(['settings.cache_ttl' => 0]);
    $this->settings = app(SettingsService::class);
});

/**
 * Normalize ICU narrow no-break spaces (U+202F) to regular spaces.
 *
 * IntlDateFormatter inserts U+202F before AM/PM markers per CLDR rules.
 */
function normalizeIcuSpaces(string $value): string
{
    return str_replace("\u{202F}", ' ', $value);
}

/**
 * Build a fresh DateTimeDisplayService instance (clears memoized state).
 */
function freshService(): DateTimeDisplayService
{
    app()->forgetInstance(DateTimeDisplayService::class);
    app()->forgetInstance(LocaleContext::class);

    return app()->make(DateTimeDisplayService::class);
}

function actingUser(?int $companyId): User
{
    $user = User::factory()->create(['company_id' => $companyId]);
    test()->actingAs($user);

    return $user;
}

function setTimezoneMode(SettingsService $settings, TimezoneMode $mode, ?Scope $scope = null): void
{
    if ($scope === null) {
        $settings->set(DT_TEST_KEY_MODE, $mode->value);

        return;
    }

    $settings->set(DT_TEST_KEY_MODE, $mode->value, $scope);
}

function setCompanyTimezoneLocale(SettingsService $settings, int $companyId, string $locale): void
{
    $settings->set(DT_TEST_KEY_DEFAULT, DT_TEST_TIMEZONE_KL, Scope::company($companyId));
    $settings->set(DT_TEST_KEY_LOCALE, $locale);
}

function utcTestCarbon(): Carbon
{
    return Carbon::parse(DT_TEST_TIMESTAMP, 'UTC');
}

function assertFormattedValues(
    DateTimeDisplayService $service,
    DateTimeInterface|string|null $value,
    string $expectedDateTime,
    string $expectedDate,
    string $expectedTime,
): void {
    expect($service->formatDateTime($value))->toBe($expectedDateTime)
        ->and($service->formatDate($value))->toBe($expectedDate)
        ->and($service->formatTime($value))->toBe($expectedTime);
}

// --- Default Behavior (no auth, no settings) ---

it('defaults to COMPANY mode when unauthenticated', function (): void {
    expect(freshService()->currentMode())->toBe(TimezoneMode::COMPANY);
});

it('defaults to UTC timezone when unauthenticated in COMPANY mode', function (): void {
    expect(freshService()->currentTimezone())->toBe('UTC');
});

it('returns em-dash for null values', function (): void {
    assertFormattedValues(freshService(), null, '—', '—', '—');
});

// --- Formatting in UTC Mode ---

it('formats datetime in UTC when mode is UTC', function (): void {
    actingUser(null);
    setTimezoneMode($this->settings, TimezoneMode::UTC);

    assertFormattedValues(freshService(), utcTestCarbon(), DT_TEST_TIMESTAMP, '2026-06-15', '08:00:00');
});

// --- Formatting in Company Mode with Timezone (ICU locale-aware) ---

it('formats datetime in company timezone with en-US locale via ICU', function (): void {
    actingUser(1);
    setCompanyTimezoneLocale($this->settings, 1, 'en-US');

    $service = freshService();
    $result = $service->formatDateTime(utcTestCarbon());

    // ICU SHORT for en-US: M/d/yy, h:mm a → 6/15/26, 4:00 PM
    // ICU uses U+202F (narrow no-break space) before AM/PM — normalize for assertion.
    $normalized = normalizeIcuSpaces($result);
    expect($normalized)->toContain('6/15')
        ->and($normalized)->toContain('4:00 PM');

    expect($service->formatDate(utcTestCarbon()))->toContain('6/15');
    expect(normalizeIcuSpaces($service->formatTime(utcTestCarbon())))->toBe('4:00 PM');
});

it('formats datetime in company timezone with ms-MY locale via ICU', function (): void {
    actingUser(1);
    setCompanyTimezoneLocale($this->settings, 1, 'ms-MY');

    $service = freshService();
    $result = $service->formatDateTime(utcTestCarbon());

    // ICU SHORT for ms-MY uses d/MM/yy pattern → 15/06/26
    expect($result)->toContain('15/06');

    expect($service->formatDate(utcTestCarbon()))->toContain('15/06');
});

it('formats en-MY dates with DD/MM/YYYY order via ICU', function (): void {
    actingUser(1);
    setCompanyTimezoneLocale($this->settings, 1, 'en-MY');

    $service = freshService();

    // ICU SHORT for en-MY uses dd/MM/y → 15/06/2026 (the bug fix!)
    // Carbon isoFormat('L') would give 06/15/2026 (US order) — wrong.
    $date = $service->formatDate(utcTestCarbon());

    expect($date)->toBe('15/06/2026');
});

it('formats en-MY times with lowercase am/pm via ICU', function (): void {
    actingUser(1);
    setCompanyTimezoneLocale($this->settings, 1, 'en-MY');

    $service = freshService();
    $time = $service->formatTime(utcTestCarbon());

    // ICU SHORT for en-MY → 4:00 pm (lowercase, Malaysian English)
    // ICU uses U+202F (narrow no-break space) before am/pm.
    expect(normalizeIcuSpaces($time))->toBe('4:00 pm');
});

// --- Local Mode Returns ISO-8601 ---

it('returns UTC ISO-8601 in local mode for browser formatting', function (): void {
    actingUser(null);
    setTimezoneMode($this->settings, TimezoneMode::LOCAL);

    $service = freshService();
    $carbon = utcTestCarbon();

    $result = $service->formatDateTime($carbon);

    expect($result)->toContain('2026-06-15T08:00:00');
    expect($service->isLocalMode())->toBeTrue();
    expect($service->currentTimezone())->toBe('UTC');
});

// --- String Input ---

it('parses string datetime values', function (): void {
    actingUser(null);
    setTimezoneMode($this->settings, TimezoneMode::UTC);

    $service = freshService();

    expect($service->formatDateTime(DT_TEST_TIMESTAMP))->toBe(DT_TEST_TIMESTAMP);
});

// --- Mode Resolution Cascade ---

it('resolves company-scoped mode override for user without employee', function (): void {
    actingUser(1);
    setTimezoneMode($this->settings, TimezoneMode::UTC, Scope::company(1));

    expect(freshService()->currentMode())->toBe(TimezoneMode::UTC);
});

it('falls back to COMPANY for invalid mode value', function (): void {
    actingUser(null);

    $this->settings->set(DT_TEST_KEY_MODE, 'invalid_mode');

    expect(freshService()->currentMode())->toBe(TimezoneMode::COMPANY);
});

it('returns configured company timezone even when active mode is utc', function (): void {
    actingUser(1);
    $this->settings->set(DT_TEST_KEY_DEFAULT, DT_TEST_TIMEZONE_KL, Scope::company(1));
    setTimezoneMode($this->settings, TimezoneMode::UTC, Scope::company(1));

    expect(freshService()->currentCompanyTimezone())->toBe(DT_TEST_TIMEZONE_KL);
});

// --- isCompanyTimezoneExplicit ---

it('reports company timezone as not explicit when setting is absent', function (): void {
    actingUser(1);

    expect(freshService()->isCompanyTimezoneExplicit())->toBeFalse();
});

it('reports company timezone as explicit when setting exists', function (): void {
    actingUser(1);
    $this->settings->set(DT_TEST_KEY_DEFAULT, DT_TEST_TIMEZONE_KL, Scope::company(1));

    expect(freshService()->isCompanyTimezoneExplicit())->toBeTrue();
});

it('reports company timezone as not explicit when unauthenticated', function (): void {
    expect(freshService()->isCompanyTimezoneExplicit())->toBeFalse();
});

// --- TimezoneMode Labels ---

it('provides translatable labels on TimezoneMode enum', function (): void {
    expect(TimezoneMode::COMPANY->label())->toBe('Company')
        ->and(TimezoneMode::LOCAL->label())->toBe('Local')
        ->and(TimezoneMode::UTC->label())->toBe('Stored');
});

it('provides translatable descriptions on TimezoneMode enum', function (): void {
    expect(TimezoneMode::COMPANY->description())->toBe('Company')
        ->and(TimezoneMode::LOCAL->description())->toBe('Local (browser)')
        ->and(TimezoneMode::UTC->description())->toBe('Stored (raw)');
});
