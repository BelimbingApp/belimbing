<?php

namespace App\Base\System\Services;

use App\Base\Settings\Contracts\SettingsService;

final readonly class MailRuntimeSettings
{
    public const string MAILER_KEY = 'mail.mailer';

    public const string SCHEME_KEY = 'mail.smtp.scheme';

    public const string HOST_KEY = 'mail.smtp.host';

    public const string PORT_KEY = 'mail.smtp.port';

    public const string USERNAME_KEY = 'mail.smtp.username';

    public const string PASSWORD_KEY = 'mail.smtp.password';

    public const string FROM_ADDRESS_KEY = 'mail.from.address';

    public const string FROM_NAME_KEY = 'mail.from.name';

    public function __construct(
        private SettingsService $settings,
        private SystemRuntimeSettings $system,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function configuration(): array
    {
        $fromName = $this->nullableString(self::FROM_NAME_KEY);

        return [
            'default' => (string) $this->settings->get(self::MAILER_KEY),
            'mailers.smtp.scheme' => $this->nullableString(self::SCHEME_KEY),
            'mailers.smtp.host' => (string) $this->settings->get(self::HOST_KEY),
            'mailers.smtp.port' => (int) $this->settings->get(self::PORT_KEY),
            'mailers.smtp.username' => $this->nullableString(self::USERNAME_KEY),
            'mailers.smtp.password' => $this->nullableString(self::PASSWORD_KEY),
            'from.address' => (string) $this->settings->get(self::FROM_ADDRESS_KEY),
            'from.name' => $fromName ?? $this->system->productName(),
        ];
    }

    private function nullableString(string $key): ?string
    {
        $value = $this->settings->get($key);

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
