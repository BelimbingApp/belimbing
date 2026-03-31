<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Base\Locale\Services;

use App\Base\Locale\Contracts\LicenseeLocaleBootstrapSource;
use App\Base\Locale\Contracts\LocaleContext;
use App\Base\Locale\DTO\ResolvedLocale;
use App\Base\Locale\Enums\LocaleSource;
use App\Base\Settings\Contracts\SettingsService;

class ApplicationLocaleContext implements LocaleContext
{
    private const SETTINGS_KEY_LOCALE = 'ui.locale';

    private const SETTINGS_KEY_SOURCE = 'ui.locale_source';

    private const SETTINGS_KEY_CONFIRMED_AT = 'ui.locale_confirmed_at';

    private const SETTINGS_KEY_INFERRED_COUNTRY = 'ui.locale_inferred_country';

    private ?ResolvedLocale $resolved = null;

    public function __construct(
        private readonly SettingsService $settings,
        private readonly LocaleCatalog $catalog,
        private readonly LicenseeLocaleBootstrapSource $bootstrapSource,
    ) {}

    public function currentLocale(): string
    {
        return $this->state()->locale;
    }

    public function currentLanguage(): string
    {
        return $this->state()->language;
    }

    public function fallbackLocale(): string
    {
        return $this->catalog->fallbackLocale();
    }

    public function forCarbon(): string
    {
        return $this->state()->carbonLocale;
    }

    public function forIntl(): string
    {
        return $this->state()->intlLocale;
    }

    public function forNumber(): string
    {
        return $this->state()->numberLocale;
    }

    public function source(): string
    {
        return $this->state()->source->value;
    }

    public function isConfirmed(): bool
    {
        return $this->state()->confirmed;
    }

    public function requiresConfirmation(): bool
    {
        return $this->state()->requiresConfirmation();
    }

    public function inferredCountry(): ?string
    {
        return $this->state()->inferredCountry;
    }

    public function state(): ResolvedLocale
    {
        if ($this->resolved !== null) {
            return $this->resolved;
        }

        $storedLocale = $this->safeGet(self::SETTINGS_KEY_LOCALE);
        $storedSource = $this->safeGet(self::SETTINGS_KEY_SOURCE);
        $confirmedAt = $this->safeGet(self::SETTINGS_KEY_CONFIRMED_AT);
        $storedCountry = $this->safeGet(self::SETTINGS_KEY_INFERRED_COUNTRY);

        $normalizedStoredLocale = $this->catalog->normalize($storedLocale);

        if ($normalizedStoredLocale !== null) {
            return $this->resolved = $this->buildResolvedLocale(
                locale: $normalizedStoredLocale,
                source: $this->resolveStoredSource($storedSource, $normalizedStoredLocale),
                confirmed: $this->isStoredLocaleConfirmed($storedSource, $confirmedAt, $normalizedStoredLocale),
                inferredCountry: is_string($storedCountry) && $storedCountry !== '' ? strtoupper($storedCountry) : null,
            );
        }

        $bootstrap = $this->bootstrapSource->resolve();

        if ($bootstrap !== null) {
            $inferredLocale = $this->catalog->inferFromBootstrap($bootstrap);

            if ($inferredLocale !== null) {
                $this->persistInferredLocale($inferredLocale, $bootstrap->countryIso);

                return $this->resolved = $this->buildResolvedLocale(
                    locale: $inferredLocale,
                    source: LocaleSource::LICENSEE_ADDRESS,
                    confirmed: false,
                    inferredCountry: strtoupper($bootstrap->countryIso),
                );
            }
        }

        return $this->resolved = $this->buildResolvedLocale(
            locale: $this->fallbackLocale(),
            source: LocaleSource::CONFIG_DEFAULT,
            confirmed: false,
        );
    }

    private function buildResolvedLocale(
        string $locale,
        LocaleSource $source,
        bool $confirmed,
        ?string $inferredCountry = null,
    ): ResolvedLocale {
        return new ResolvedLocale(
            locale: $locale,
            language: $this->catalog->language($locale),
            carbonLocale: str_replace('-', '_', $locale),
            intlLocale: $locale,
            numberLocale: str_replace('-', '_', $locale),
            source: $source,
            confirmed: $confirmed,
            inferredCountry: $inferredCountry,
        );
    }

    private function persistInferredLocale(string $locale, string $countryIso): void
    {
        try {
            $this->settings->set(self::SETTINGS_KEY_LOCALE, $locale);
            $this->settings->set(self::SETTINGS_KEY_SOURCE, LocaleSource::LICENSEE_ADDRESS->value);
            $this->settings->set(self::SETTINGS_KEY_INFERRED_COUNTRY, strtoupper($countryIso));
            $this->settings->forget(self::SETTINGS_KEY_CONFIRMED_AT);
        } catch (\Throwable) {
            // During early setup the settings table may not be ready yet.
        }
    }

    private function resolveStoredSource(mixed $source, string $storedLocale): LocaleSource
    {
        if (! is_string($source) || $source === '') {
            return $storedLocale !== '' ? LocaleSource::MANUAL : LocaleSource::CONFIG_DEFAULT;
        }

        return LocaleSource::tryFrom($source) ?? LocaleSource::MANUAL;
    }

    private function isStoredLocaleConfirmed(mixed $source, mixed $confirmedAt, string $storedLocale): bool
    {
        if ($storedLocale === '') {
            return false;
        }

        if (! is_string($source) || $source === '' || $source === LocaleSource::MANUAL->value) {
            return true;
        }

        return is_string($confirmedAt) && trim($confirmedAt) !== '';
    }

    private function safeGet(string $key): mixed
    {
        try {
            return $this->settings->get($key);
        } catch (\Throwable) {
            return null;
        }
    }
}
