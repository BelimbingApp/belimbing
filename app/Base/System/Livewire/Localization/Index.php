<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Base\System\Livewire\Localization;

use App\Base\Locale\Contracts\LicenseeLocaleBootstrapSource;
use App\Base\Locale\Contracts\LocaleContext;
use App\Base\Locale\Enums\LocaleSource;
use App\Base\Locale\Services\LocaleCatalog;
use App\Base\Settings\Contracts\SettingsService;
use Carbon\Carbon;
use Illuminate\Support\Number;
use Illuminate\Validation\Rule;
use Livewire\Component;

class Index extends Component
{
    private const SETTINGS_KEY_LOCALE = 'ui.locale';

    private const SETTINGS_KEY_SOURCE = 'ui.locale_source';

    private const SETTINGS_KEY_CONFIRMED_AT = 'ui.locale_confirmed_at';

    private const SETTINGS_KEY_INFERRED_COUNTRY = 'ui.locale_inferred_country';

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
     */
    public function save(SettingsService $settings, LocaleCatalog $catalog): void
    {
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

        session()->flash('locale-status', __('Localization saved.'));

        $this->redirectRoute('admin.system.localization.index', navigate: true);
    }

    public function render(
        LocaleCatalog $catalog,
        LocaleContext $localeContext,
        LicenseeLocaleBootstrapSource $bootstrapSource,
    ): \Illuminate\Contracts\View\View {
        $state = $localeContext->state();
        $previewLocale = $catalog->normalize($this->selectedLocale) ?? $state->locale;
        $sample = Carbon::create(2026, 3, 31, 20, 15, 0, 'Asia/Kuala_Lumpur')
            ->locale(str_replace('-', '_', $previewLocale));
        $bootstrap = $bootstrapSource->resolve();
        $currencyCode = $bootstrap?->currencyCode ?: config('locale.sample_currency', 'USD');

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
                'date' => $sample->isoFormat('L'),
                'time' => $sample->isoFormat('LT'),
                'datetime' => $sample->isoFormat('L LT'),
                'number' => (string) Number::format(1234567.89, precision: 2, locale: str_replace('-', '_', $previewLocale)),
                'currency' => (string) Number::currency(1234.56, strtoupper($currencyCode), locale: str_replace('-', '_', $previewLocale)),
                'currency_code' => $currencyCode,
            ],
            'bootstrap' => [
                'country_iso' => $bootstrap?->countryIso,
                'country_name' => $bootstrap?->countryName,
                'suggested_locale' => $bootstrap ? $catalog->inferFromBootstrap($bootstrap) : null,
            ],
        ]);
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
