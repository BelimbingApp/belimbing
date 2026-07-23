<?php

namespace App\Base\Locale\Services;

use App\Base\Settings\Contracts\SettingsService;
use App\Base\Settings\DTO\Scope;
use App\Modules\Core\User\Models\User;

final readonly class LocaleSettings
{
    public const string LOCALE_KEY = 'ui.locale';

    public function __construct(
        private SettingsService $settings,
        private LocaleCatalog $catalog,
    ) {}

    public function global(): string
    {
        return $this->normalize($this->settings->get(self::LOCALE_KEY));
    }

    public function forUser(User $user): string
    {
        return $this->normalize($this->settings->get(
            self::LOCALE_KEY,
            Scope::user((int) $user->getKey(), $user->getCompanyId()),
        ));
    }

    public function hasUserOverride(User $user): bool
    {
        return $this->settings->has(
            self::LOCALE_KEY,
            Scope::user((int) $user->getKey(), $user->getCompanyId()),
        );
    }

    public function setGlobal(string $locale): void
    {
        $this->settings->set(self::LOCALE_KEY, $this->normalize($locale));
    }

    public function setForUser(User $user, string $locale): void
    {
        $this->settings->set(
            self::LOCALE_KEY,
            $this->normalize($locale),
            Scope::user((int) $user->getKey(), $user->getCompanyId()),
        );
    }

    public function forgetForUser(User $user): void
    {
        $this->settings->forget(
            self::LOCALE_KEY,
            Scope::user((int) $user->getKey(), $user->getCompanyId()),
        );
    }

    private function normalize(mixed $locale): string
    {
        return $this->catalog->normalize($locale)
            ?? $this->catalog->normalize(config('locale.fallback_locale'))
            ?? 'en-MY';
    }
}
