<?php

use App\Base\Locale\Contracts\LocaleContext;
use App\Base\Locale\Enums\LocaleSource;
use App\Base\Settings\Contracts\SettingsService;
use App\Modules\Core\Address\Models\Address;
use App\Modules\Core\Company\Models\Company;
use App\Modules\Core\Geonames\Models\Country;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, LazilyRefreshDatabase::class);

const LOCALE_SETTINGS_KEY = 'ui.locale';
const LOCALE_SOURCE_SETTINGS_KEY = 'ui.locale_source';
const LOCALE_CONFIRMED_AT_SETTINGS_KEY = 'ui.locale_confirmed_at';
const LOCALE_INFERRED_COUNTRY_SETTINGS_KEY = 'ui.locale_inferred_country';
const LOCALE_TEST_LICENSEE_NAME = 'Licensee Company';

beforeEach(function (): void {
    config(['settings.cache_ttl' => 0]);
    $this->settings = app(SettingsService::class);
});

function freshLocaleContext(): LocaleContext
{
    app()->forgetInstance(LocaleContext::class);

    return app(LocaleContext::class);
}

function seedLicenseeAddressCountry(
    string $countryIso,
    string $languages,
    string $currencyCode = 'USD',
): void {
    Country::query()->create([
        'iso' => $countryIso,
        'iso3' => $countryIso.'X',
        'iso_numeric' => '001',
        'country' => $countryIso,
        'continent' => 'AS',
        'languages' => $languages,
        'currency_code' => $currencyCode,
    ]);

    Company::unguarded(fn () => Company::query()->firstOrCreate(
        ['id' => Company::LICENSEE_ID],
        [
            'name' => LOCALE_TEST_LICENSEE_NAME,
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

it('uses a stored manual locale when present', function (): void {
    $this->settings->set(LOCALE_SETTINGS_KEY, 'fr-FR');
    $this->settings->set(LOCALE_SOURCE_SETTINGS_KEY, LocaleSource::MANUAL->value);

    $context = freshLocaleContext();

    expect($context->currentLocale())->toBe('fr-FR')
        ->and($context->currentLanguage())->toBe('fr')
        ->and($context->source())->toBe(LocaleSource::MANUAL->value)
        ->and($context->isConfirmed())->toBeTrue();
});

it('infers and persists an unconfirmed locale from the licensee address country', function (): void {
    seedLicenseeAddressCountry('MY', 'ms-MY,en-MY', 'MYR');

    $context = freshLocaleContext();

    expect($context->currentLocale())->toBe('en-MY')
        ->and($context->source())->toBe(LocaleSource::LICENSEE_ADDRESS->value)
        ->and($context->requiresConfirmation())->toBeTrue()
        ->and($this->settings->get(LOCALE_SETTINGS_KEY))->toBe('en-MY')
        ->and($this->settings->get(LOCALE_SOURCE_SETTINGS_KEY))->toBe(LocaleSource::LICENSEE_ADDRESS->value)
        ->and($this->settings->get(LOCALE_INFERRED_COUNTRY_SETTINGS_KEY))->toBe('MY');
});

it('falls back to the configured app locale when no licensee locale can be inferred', function (): void {
    config(['app.locale' => 'fr']);

    $context = freshLocaleContext();

    expect($context->currentLocale())->toBe('fr-FR')
        ->and($context->source())->toBe(LocaleSource::CONFIG_DEFAULT->value)
        ->and($context->requiresConfirmation())->toBeTrue();
});
