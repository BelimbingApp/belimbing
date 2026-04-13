<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Base\System\Livewire\Localization;

use App\Base\DateTime\Contracts\DateTimeDisplayService;
use App\Base\Locale\Contracts\LicenseeLocaleBootstrapSource;
use App\Base\Locale\Contracts\LocaleContext;
use App\Base\Locale\Enums\LocaleSource;
use App\Base\Locale\Services\LocaleCatalog;
use App\Base\Settings\Contracts\SettingsService;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Number;
use Illuminate\Validation\Rule;
use IntlDateFormatter;
use Livewire\Component;

class Index extends Component
{
    private const SETTINGS_KEY_LOCALE = 'ui.locale';

    private const SETTINGS_KEY_SOURCE = 'ui.locale_source';

    private const SETTINGS_KEY_CONFIRMED_AT = 'ui.locale_confirmed_at';

    private const SETTINGS_KEY_INFERRED_COUNTRY = 'ui.locale_inferred_country';

    private const SETTINGS_KEY_CURRENCY = 'ui.locale_currency';

    public string $selectedLocale = '';

    /**
     * @var array<int, array{value: string, label: string}>
     */
    public array $localeOptions = [];

    public function mount(LocaleCatalog $catalog, LocaleContext $localeContext): void
    {
        $this->localeOptions = collect($catalog->options())
            ->map(fn (string $label, string $code) => [
                'value' => $code,
                'label' => $label.' ('.$code.')',
            ])
            ->values()
            ->all();
        $this->selectedLocale = $localeContext->currentLocale();
    }

    /**
     * Persist the selected locale as the confirmed application locale.
     *
     * Also derives and persists the currency code from the licensee's
     * Geonames country record when available.
     */
    public function save(
        SettingsService $settings,
        LocaleCatalog $catalog,
        LicenseeLocaleBootstrapSource $bootstrapSource,
    ): void {
        $this->validate([
            'selectedLocale' => ['required', 'string', Rule::in(array_keys($catalog->options()))],
        ]);

        $locale = $catalog->normalize($this->selectedLocale);

        if ($locale === null) {
            $this->addError('selectedLocale', __('Select a supported locale.'));

            return;
        }

        $settings->set(self::SETTINGS_KEY_LOCALE, $locale);
        $settings->set(self::SETTINGS_KEY_SOURCE, LocaleSource::MANUAL->value);
        $settings->set(self::SETTINGS_KEY_CONFIRMED_AT, now()->toIso8601String());
        $settings->forget(self::SETTINGS_KEY_INFERRED_COUNTRY);

        $bootstrap = $bootstrapSource->resolve();

        if ($bootstrap?->currencyCode) {
            $settings->set(self::SETTINGS_KEY_CURRENCY, strtoupper($bootstrap->currencyCode));
        }

        session()->flash('locale-status', __('Localization saved.'));

        $this->redirectRoute('admin.system.localization.index', navigate: true);
    }

    public function render(
        LocaleCatalog $catalog,
        LocaleContext $localeContext,
        LicenseeLocaleBootstrapSource $bootstrapSource,
        DateTimeDisplayService $dateTimeDisplay,
        SettingsService $settings,
    ): View {
        $state = $localeContext->state();
        $previewLocale = $catalog->normalize($this->selectedLocale) ?? $state->locale;
        $intlLocale = $previewLocale;
        $numberLocale = str_replace('-', '_', $previewLocale);
        $sampleTimestamp = now()->getTimestamp();
        $bootstrap = $bootstrapSource->resolve();
        $persistedCurrency = $settings->get(self::SETTINGS_KEY_CURRENCY);
        $currencyCode = $persistedCurrency
            ?: ($bootstrap?->currencyCode ?: config('locale.sample_currency', 'USD'));
        $companyTimezone = $dateTimeDisplay->currentCompanyTimezone();
        $companyTimezoneExplicit = $dateTimeDisplay->isCompanyTimezoneExplicit();

        return view('livewire.admin.system.localization.index', [
            'current' => [
                'locale' => $state->locale,
                'label' => $catalog->label($state->locale),
                'language' => $state->language,
                'source' => $this->sourceLabel($state->source),
                'source_code' => $state->source->value,
                'confirmed' => $state->confirmed,
                'inferred_country' => $state->inferredCountry,
            ],
            'preview' => [
                'locale' => $previewLocale,
                'label' => $catalog->label($previewLocale),
                'date' => $this->intlFormat($intlLocale, IntlDateFormatter::SHORT, IntlDateFormatter::NONE, $sampleTimestamp),
                'time' => $this->intlFormat($intlLocale, IntlDateFormatter::NONE, IntlDateFormatter::SHORT, $sampleTimestamp),
                'datetime' => $this->intlFormat($intlLocale, IntlDateFormatter::SHORT, IntlDateFormatter::SHORT, $sampleTimestamp),
                'number' => (string) Number::format(1234567.89, precision: 2, locale: $numberLocale),
                'currency' => (string) Number::currency(1234.56, strtoupper($currencyCode), locale: $numberLocale),
                'currency_code' => $currencyCode,
            ],
            'context' => [
                'company_timezone' => $companyTimezone,
                'company_timezone_explicit' => $companyTimezoneExplicit,
                'currency_code' => $currencyCode,
                'language' => $state->language,
            ],
            'bootstrap' => [
                'country_iso' => $bootstrap?->countryIso,
                'country_name' => $bootstrap?->countryName,
                'suggested_locale' => $bootstrap ? $catalog->inferFromBootstrap($bootstrap) : null,
            ],
        ]);
    }

    /**
     * Format a timestamp using ICU IntlDateFormatter.
     */
    private function intlFormat(string $locale, int $dateType, int $timeType, int $timestamp): string
    {
        $formatter = new IntlDateFormatter($locale, $dateType, $timeType);
        $result = $formatter->format($timestamp);

        return $result !== false ? $result : '—';
    }

    private function sourceLabel(LocaleSource $source): string
    {
        return match ($source) {
            LocaleSource::MANUAL => __('Confirmed manually'),
            LocaleSource::LICENSEE_ADDRESS => __('Inferred from licensee address'),
            LocaleSource::CONFIG_DEFAULT => __('Using application default'),
        };
    }
}
