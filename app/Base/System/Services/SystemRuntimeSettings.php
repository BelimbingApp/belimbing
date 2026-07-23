<?php

namespace App\Base\System\Services;

use App\Base\Settings\Contracts\SettingsService;

final readonly class SystemRuntimeSettings
{
    public const string PRODUCT_NAME_KEY = 'system.identity.name';

    public const string SESSION_LIFETIME_KEY = 'session.lifetime_minutes';

    public function __construct(
        private SettingsService $settings,
    ) {}

    public function productName(): string
    {
        $name = trim((string) $this->settings->get(self::PRODUCT_NAME_KEY));

        return $name !== '' ? $name : 'Belimbing';
    }

    public function sessionLifetimeMinutes(): int
    {
        return max(1, (int) $this->settings->get(self::SESSION_LIFETIME_KEY));
    }
}
