<?php

use App\Base\Locale\Contracts\LocaleContext;
use App\Base\Locale\Enums\LocaleSource;
use App\Base\Settings\Contracts\SettingsService;
use App\Base\Settings\DTO\Scope;
use App\Base\System\Livewire\Localization\Index as LocalizationIndex;
use App\Modules\Core\Address\Models\Address;
use App\Modules\Core\Company\Models\Company;
use App\Modules\Core\Geonames\Models\Country;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

const FEATURE_LOCALE_SETTINGS_KEY = 'ui.locale';
const FEATURE_LOCALE_SOURCE_SETTINGS_KEY = 'ui.locale_source';
const FEATURE_LOCALE_CONFIRMED_AT_SETTINGS_KEY = 'ui.locale_confirmed_at';
const FEATURE_LOCALE_INFERRED_COUNTRY_SETTINGS_KEY = 'ui.locale_inferred_country';
const FEATURE_TIMEZONE_DEFAULT_SETTINGS_KEY = 'ui.timezone.default';
const FEATURE_TIMEZONE_MODE_SETTINGS_KEY = 'ui.timezone.mode';
const FEATURE_TIMEZONE_KUALA_LUMPUR = 'Asia/Kuala_Lumpur';

beforeEach(function (): void {
    config(['settings.cache_ttl' => 0]);
    setupAuthzRoles();
    $this->user = createAdminUser();
    $this->actingAs($this->user);
    $this->settings = app(SettingsService::class);
});

function seedFeatureLicenseeLocale(string $countryIso = 'MY', string $languages = 'ms-MY,en-MY'): void
{
    Country::query()->create([
        'iso' => $countryIso,
        'iso3' => $countryIso.'X',
        'iso_numeric' => '001',
        'country' => 'Malaysia',
        'continent' => 'AS',
        'languages' => $languages,
        'currency_code' => 'MYR',
    ]);

    Company::unguarded(fn () => Company::query()->firstOrCreate(
        ['id' => Company::LICENSEE_ID],
        [
            'name' => 'Licensee',
            'status' => 'active',
        ],
    ));

    $address = Address::factory()->create(['country_iso' => $countryIso]);

    Company::query()
        ->findOrFail(Company::LICENSEE_ID)
        ->addresses()
        ->attach($address->id, [
            'kind' => json_encode(['headquarters']),
            'is_primary' => true,
            'priority' => 0,
        ]);
}

it('renders the localization page for admins', function (): void {
    $response = $this->get(route('admin.system.localization.index'));

    $response->assertOk()
        ->assertSee('Language & Region')
        ->assertSee('Localization');
});

it('saves and confirms the selected locale from the localization page', function (): void {
    seedFeatureLicenseeLocale();
    app()->forgetInstance(LocaleContext::class);
    app(LocaleContext::class)->state();

    Livewire::test(LocalizationIndex::class)
        ->set('selectedLocale', 'ms-MY')
        ->call('save')
        ->assertRedirect(route('admin.system.localization.index'));

    expect($this->settings->get(FEATURE_LOCALE_SETTINGS_KEY))->toBe('ms-MY')
        ->and($this->settings->get(FEATURE_LOCALE_SOURCE_SETTINGS_KEY))->toBe(LocaleSource::MANUAL->value)
        ->and($this->settings->get(FEATURE_LOCALE_CONFIRMED_AT_SETTINGS_KEY))->not->toBeNull()
        ->and($this->settings->get(FEATURE_LOCALE_INFERRED_COUNTRY_SETTINGS_KEY))->toBeNull();
});

it('binds the locale combobox to the selectedLocale property', function (): void {
    seedFeatureLicenseeLocale();
    app()->forgetInstance(LocaleContext::class);

    $html = Livewire::test(LocalizationIndex::class)->html();

    expect($html)
        ->toContain("entangle('selectedLocale')")
        ->not->toContain('$selectedLocale');
});

it('shows a status-bar warning while the inferred locale is unconfirmed', function (): void {
    seedFeatureLicenseeLocale();
    app()->forgetInstance(LocaleContext::class);

    $response = $this->get(route('admin.system.info.index'));

    $response->assertOk()
        ->assertSee('Locale inferred: en-MY');
});

it('clears the status-bar warning after the locale is confirmed', function (): void {
    seedFeatureLicenseeLocale();
    app()->forgetInstance(LocaleContext::class);
    app(LocaleContext::class)->state();

    $this->settings->set(FEATURE_LOCALE_SETTINGS_KEY, 'en-MY');
    $this->settings->set(FEATURE_LOCALE_SOURCE_SETTINGS_KEY, LocaleSource::MANUAL->value);
    $this->settings->set(FEATURE_LOCALE_CONFIRMED_AT_SETTINGS_KEY, now()->toIso8601String());
    $this->settings->forget(FEATURE_LOCALE_INFERRED_COUNTRY_SETTINGS_KEY);

    app()->forgetInstance(LocaleContext::class);

    $response = $this->get(route('admin.system.info.index'));

    $response->assertOk()
        ->assertDontSee('Locale inferred: en-MY')
        ->assertDontSee('Locale not confirmed');
});

it('renders the preview using the resolved company timezone', function (): void {
    $sample = CarbonImmutable::parse('2026-04-13 20:15:00', 'UTC');
    $this->travelTo($sample);

    $this->settings->set(
        FEATURE_TIMEZONE_DEFAULT_SETTINGS_KEY,
        FEATURE_TIMEZONE_KUALA_LUMPUR,
        Scope::company($this->user->company_id),
    );

    $expectedDateTime = (new IntlDateFormatter(
        'en-MY',
        IntlDateFormatter::SHORT,
        IntlDateFormatter::SHORT,
        FEATURE_TIMEZONE_KUALA_LUMPUR,
    ))->format($sample->getTimestamp());

    $html = Livewire::test(LocalizationIndex::class)
        ->set('selectedLocale', 'en-MY')
        ->html();

    expect($html)->toContain((string) $expectedDateTime);

    $this->travelBack();
});

it('renders browser-side preview hooks when timezone mode is local', function (): void {
    $sample = CarbonImmutable::parse('2026-04-13 20:15:00', 'UTC');
    $this->travelTo($sample);

    $this->settings->set(FEATURE_TIMEZONE_MODE_SETTINGS_KEY, 'local');

    $html = Livewire::test(LocalizationIndex::class)
        ->set('selectedLocale', 'en-MY')
        ->html();

    expect($html)
        ->toContain('data-format="date"')
        ->toContain('data-format="time"')
        ->toContain('data-format="datetime"')
        ->toContain('data-locale="en-MY"')
        ->toContain('datetime="')
        ->toContain('window.blbFormatDateTimeElement?.($el)');

    $this->travelBack();
});
