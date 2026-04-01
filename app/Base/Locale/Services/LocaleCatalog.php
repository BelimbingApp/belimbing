<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Base\Locale\Services;

use App\Base\Locale\DTO\LicenseeLocaleBootstrap;

class LocaleCatalog
{
    /**
     * @return array<string, array{label: string, language: string}>
     */
    public function supportedLocales(): array
    {
        /** @var array<string, array{label: string, language: string}> $locales */
        return config('locale.supported_locales', []);
    }

    /**
     * @return array<string, string>
     */
    public function options(): array
    {
        return collect($this->supportedLocales())
            ->mapWithKeys(fn (array $locale, string $code) => [$code => $locale['label']])
            ->all();
    }

    public function supports(string $locale): bool
    {
        return array_key_exists($locale, $this->supportedLocales());
    }

    public function label(string $locale): string
    {
        return $this->supportedLocales()[$locale]['label'] ?? $locale;
    }

    public function language(string $locale): string
    {
        return $this->supportedLocales()[$locale]['language'] ?? strtolower(explode('-', $locale)[0]);
    }

    public function fallbackLocale(): string
    {
        return $this->normalize(config('app.locale'))
            ?? $this->normalize(config('locale.fallback_locale'))
            ?? 'en-US';
    }

    public function normalize(mixed $locale): ?string
    {
        if (! is_string($locale)) {
            return null;
        }

        $trimmed = trim($locale);

        if ($trimmed === '') {
            return null;
        }

        $canonical = str_replace('_', '-', $trimmed);
        $parts = explode('-', $canonical);
        $language = strtolower(array_shift($parts) ?? '');

        if ($language === '') {
            return null;
        }

        if ($parts === []) {
            $default = config("locale.language_defaults.{$language}");

            return is_string($default) && $this->supports($default)
                ? $default
                : null;
        }

        $region = strtoupper((string) ($parts[0] ?? ''));
        $normalized = $language.'-'.$region;

        return $this->supports($normalized) ? $normalized : null;
    }

    public function inferFromBootstrap(LicenseeLocaleBootstrap $bootstrap): ?string
    {
        $countryIso = strtoupper($bootstrap->countryIso);
        $override = config("locale.country_locale_overrides.{$countryIso}");

        if (is_string($override) && $this->supports($override)) {
            return $override;
        }

        foreach ($this->languageCandidates($bootstrap) as $candidate) {
            $normalized = $this->normalize($candidate);

            if ($normalized !== null) {
                return $normalized;
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private function languageCandidates(LicenseeLocaleBootstrap $bootstrap): array
    {
        $countryIso = strtoupper($bootstrap->countryIso);
        $languages = collect(explode(',', (string) $bootstrap->languages))
            ->map(fn (string $language) => trim($language))
            ->filter()
            ->values();

        $candidates = [];

        foreach ($languages as $language) {
            $normalized = str_replace('_', '-', $language);
            $parts = explode('-', $normalized);
            $baseLanguage = strtolower($parts[0] ?? '');

            if ($baseLanguage === '') {
                continue;
            }

            if (count($parts) > 1) {
                $candidates[] = $baseLanguage.'-'.strtoupper($parts[1]);
            }

            $candidates[] = $baseLanguage.'-'.$countryIso;
            $candidates[] = $baseLanguage;
        }

        return array_values(array_unique($candidates));
    }
}
