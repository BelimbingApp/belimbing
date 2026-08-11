<?php

use App\Base\Locale\Contracts\LocaleContext;
use App\Base\Locale\Enums\LocaleSource;
use App\Base\Settings\Contracts\SettingsService;
use App\Core\Address\Models\Address;
use App\Core\Geonames\Models\Country;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, LazilyRefreshDatabase::class);

const LOCALE_SETTINGS_KEY = 'ui.locale';
const LOCALE_SOURCE_SETTINGS_KEY = 'ui.locale_source';
const LOCALE_INFERRED_COUNTRY_SETTINGS_KEY = 'ui.locale_inferred_country';
const LOCALE_TEST_OPERATOR_NAME = 'Platform Operator Company';

beforeEach(function (): void {
    config(['settings.cache_ttl' => 0]);
    $this->settings = app(SettingsService::class);
});

function freshLocaleContext(): LocaleContext
{
    app()->forgetInstance(LocaleContext::class);

    return app(LocaleContext::class);
}

function seedOperatorAddressCountry(
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

    provisionPlatformOperatorCompany(LOCALE_TEST_OPERATOR_NAME);

    $address = Address::factory()->create(['country_iso' => $countryIso]);

    platformOperatorCompany()
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
        ->and($context->source())->toBe(LocaleSource::MANUAL->value);
});

it('infers and persists a locale from the platform-operator address country', function (): void {
    seedOperatorAddressCountry('MY', 'ms-MY,en-MY', 'MYR');

    $context = freshLocaleContext();

    expect($context->currentLocale())->toBe('en-MY')
        ->and($context->source())->toBe(LocaleSource::PLATFORM_OPERATOR_ADDRESS->value)
        ->and($this->settings->get(LOCALE_SETTINGS_KEY))->toBe('en-MY')
        ->and($this->settings->get(LOCALE_SOURCE_SETTINGS_KEY))->toBe(LocaleSource::PLATFORM_OPERATOR_ADDRESS->value)
        ->and($this->settings->get(LOCALE_INFERRED_COUNTRY_SETTINGS_KEY))->toBe('MY');
});

it('uses the declared locale default when no operator locale can be inferred', function (): void {
    config(['app.locale' => 'fr']);

    $context = freshLocaleContext();

    expect($context->currentLocale())->toBe('en-MY')
        ->and($context->source())->toBe(LocaleSource::DECLARED_DEFAULT->value);
});
