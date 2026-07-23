<?php

namespace App\Base\System\Livewire\Localization;

use App\Base\DateTime\Contracts\DateTimeDisplayService;
use App\Base\DateTime\Enums\TimezoneMode;
use App\Base\DateTime\Services\TimezoneSettings;
use App\Base\Locale\Contracts\LicenseeLocaleBootstrapSource;
use App\Base\Locale\Contracts\LocaleContext;
use App\Base\Locale\DTO\LicenseeLocaleBootstrap;
use App\Base\Locale\Enums\LocaleSource;
use App\Base\Locale\Services\LocaleCatalog;
use App\Base\Settings\Contracts\SettingsService;
use App\Modules\Core\Geonames\Models\Country;
use Carbon\CarbonInterface;
use DateTimeZone;
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

    public string $selectedLocale = '';

    public ?string $companyTimezone = '';

    /**
     * @var array<int, array{value: string, label: string}>
     */
    public array $localeOptions = [];

    public function mount(
        LocaleCatalog $catalog,
        LocaleContext $localeContext,
        TimezoneSettings $timezoneSettings,
    ): void {
        $this->localeOptions = collect($catalog->options())
            ->map(fn (string $label, string $code) => [
                'value' => $code,
                'label' => $label.' ('.$code.')',
            ])
            ->values()
            ->all();
        $this->selectedLocale = $localeContext->currentLocale();

        $companyId = auth()->user()?->company_id;
        $this->companyTimezone = $companyId
            ? $timezoneSettings->explicitCompanyTimezone((int) $companyId) ?? ''
            : '';
    }

    /**
     * Persist the selected locale as the confirmed application locale.
     *
     * Fired by the edit-in-place combobox when the admin commits a selection.
     * Currency is no longer persisted here — it is derived from the locale's
     * region at render time.
     */
    public function updatedSelectedLocale(string $value): void
    {
        $catalog = app(LocaleCatalog::class);
        $settings = app(SettingsService::class);

        $this->validate([
            'selectedLocale' => ['required', 'string', Rule::in(array_keys($catalog->options()))],
        ]);

        $locale = $catalog->normalize($value);

        if ($locale === null) {
            $this->addError('selectedLocale', __('Select a supported locale.'));

            return;
        }

        $settings->set(self::SETTINGS_KEY_LOCALE, $locale);
        $settings->set(self::SETTINGS_KEY_SOURCE, LocaleSource::MANUAL->value);
        $settings->set(self::SETTINGS_KEY_CONFIRMED_AT, now()->toIso8601String());
        $settings->forget(self::SETTINGS_KEY_INFERRED_COUNTRY);
    }

    /**
     * Persist the company default timezone (company-scoped).
     *
     * Fired by the edit-in-place combobox when the admin commits or clears
     * the timezone. Clearing removes the setting, falling back to UTC.
     */
    public function updatedCompanyTimezone(?string $value): void
    {
        $tz = trim((string) $value);

        if ($tz !== '' && ! in_array($tz, DateTimeZone::listIdentifiers(), true)) {
            $this->addError('companyTimezone', __('Select a valid timezone.'));

            return;
        }

        $companyId = auth()->user()?->company_id;

        if (! $companyId) {
            return;
        }

        $timezoneSettings = app(TimezoneSettings::class);

        if ($tz === '') {
            $timezoneSettings->forgetCompanyTimezone((int) $companyId);
        } else {
            $timezoneSettings->setCompanyTimezone((int) $companyId, $tz);
        }
    }

    public function render(
        LocaleCatalog $catalog,
        LocaleContext $localeContext,
        LicenseeLocaleBootstrapSource $bootstrapSource,
        DateTimeDisplayService $dateTimeDisplay,
    ): View {
        $state = $localeContext->state();
        $previewLocale = $catalog->normalize($this->selectedLocale) ?? $state->locale;
        $intlLocale = $previewLocale;
        $numberLocale = str_replace('-', '_', $previewLocale);
        $sample = now();
        $bootstrap = $bootstrapSource->resolve();
        $currencyCode = $this->deriveCurrencyCode($previewLocale);
        $previewMode = $dateTimeDisplay->currentMode();
        $previewTimezone = $dateTimeDisplay->currentTimezone();
        $localPreview = $previewMode === TimezoneMode::LOCAL;

        $timezoneOptions = $this->timezoneOptions($previewTimezone);

        $localeHelp = $this->localeHelpText($catalog, $bootstrap);

        $companyId = auth()->user()?->company_id;
        $auditSubjects = [
            ['name' => 'setting', 'id' => self::SETTINGS_KEY_LOCALE],
            ['name' => 'setting', 'id' => self::SETTINGS_KEY_SOURCE],
            ['name' => 'setting', 'id' => self::SETTINGS_KEY_CONFIRMED_AT],
        ];

        if ($companyId) {
            $auditSubjects[] = [
                'name' => 'setting',
                'id' => TimezoneSettings::LOCALIZATION_TIMEZONE_KEY.'@company:'.$companyId,
            ];
        }

        return view('livewire.admin.system.localization.index', [
            'current' => [
                'locale' => $state->locale,
                'label' => $catalog->label($state->locale),
                'language' => $state->language,
                'source' => $this->sourceLabel($state->source),
                'source_code' => $state->source->value,
                'confirmed' => $state->confirmed,
            ],
            'preview' => [
                'locale' => $previewLocale,
                'label' => $catalog->label($previewLocale),
                'date' => $this->intlFormat($intlLocale, IntlDateFormatter::SHORT, IntlDateFormatter::NONE, $sample, $previewTimezone),
                'time' => $this->intlFormat($intlLocale, IntlDateFormatter::NONE, IntlDateFormatter::SHORT, $sample, $previewTimezone),
                'datetime' => $this->intlFormat($intlLocale, IntlDateFormatter::SHORT, IntlDateFormatter::SHORT, $sample, $previewTimezone),
                'number' => (string) Number::format(1234567.89, precision: 2, locale: $numberLocale),
                'currency' => (string) Number::currency(1234.56, strtoupper($currencyCode), locale: $numberLocale),
                'currency_code' => $currencyCode,
                'sample_iso' => $sample->utc()->toIso8601String(),
                'timezone_mode' => $previewMode->value,
                'local_mode' => $localPreview,
            ],
            'currency_code' => $currencyCode,
            'timezoneOptions' => $timezoneOptions,
            'localeHelp' => $localeHelp,
            'auditSubjects' => $auditSubjects,
        ]);
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    private function timezoneOptions(string $previewTimezone): array
    {
        return collect([
            $this->companyTimezone,
            $previewTimezone,
            config('app.timezone'),
            'UTC',
            'Asia/Kuala_Lumpur',
            'Asia/Singapore',
            'Asia/Jakarta',
            'Asia/Bangkok',
            'Europe/London',
            'America/New_York',
            'America/Los_Angeles',
        ])
            ->filter(fn (mixed $tz): bool => is_string($tz) && $tz !== '')
            ->unique()
            ->values()
            ->map(fn (string $tz): array => ['value' => $tz, 'label' => $tz])
            ->all();
    }

    /**
     * Derive the currency code from the locale's region via Geonames.
     *
     * Falls back to the configured sample currency when the locale has no
     * region component or the country is not in the Geonames table.
     */
    private function deriveCurrencyCode(string $locale): string
    {
        $parts = explode('-', str_replace('_', '-', $locale));
        $region = strtoupper($parts[1] ?? '');

        if ($region === '') {
            return strtoupper((string) config('locale.sample_currency', 'USD'));
        }

        try {
            $code = Country::query()->where('iso', $region)->value('currency_code');

            return $code !== null && $code !== ''
                ? strtoupper((string) $code)
                : strtoupper((string) config('locale.sample_currency', 'USD'));
        } catch (\Throwable) {
            return strtoupper((string) config('locale.sample_currency', 'USD'));
        }
    }

    /**
     * Build the help text for the locale edit-in-place combobox.
     */
    private function localeHelpText(LocaleCatalog $catalog, ?LicenseeLocaleBootstrap $bootstrap): string
    {
        if ($bootstrap?->countryIso) {
            $suggested = $catalog->inferFromBootstrap($bootstrap);
            $countryName = $bootstrap->countryName ?: $bootstrap->countryIso;

            if ($suggested) {
                return __('Locale :locale was inferred from the licensee address (:country). Confirm it if it is correct, or choose another locale.', [
                    'locale' => $suggested,
                    'country' => $countryName,
                ]);
            }

            return __('The licensee address country :country is available, but Belimbing does not have a supported default locale mapping for it yet.', [
                'country' => $countryName,
            ]);
        }

        return __('No licensee address country is available yet, so Belimbing cannot infer a default locale automatically.');
    }

    /**
     * Format a timestamp using ICU IntlDateFormatter.
     */
    private function intlFormat(
        string $locale,
        int $dateType,
        int $timeType,
        CarbonInterface $sample,
        string $timezone,
    ): string {
        $formatter = new IntlDateFormatter($locale, $dateType, $timeType, $timezone);
        $result = $formatter->format($sample->getTimestamp());

        return $result !== false ? $result : '—';
    }

    private function sourceLabel(LocaleSource $source): string
    {
        return match ($source) {
            LocaleSource::MANUAL => __('Confirmed manually'),
            LocaleSource::LICENSEE_ADDRESS => __('Inferred from licensee address'),
            LocaleSource::DECLARED_DEFAULT => __('Using declared default'),
        };
    }
}
