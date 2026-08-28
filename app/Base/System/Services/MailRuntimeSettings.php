<?php

namespace App\Base\System\Services;

use App\Base\Settings\Contracts\SettingsService;
use App\Base\System\Enums\MailPurpose;

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
     * Purpose-specific From/Reply-To override key, e.g.
     * "mail.purposes.account_security.from_address". Never a distinct SMTP
     * credential or mailer — SMTP transport stays global (#377).
     */
    public static function purposeFromAddressKey(MailPurpose $purpose): string
    {
        return $purpose->settingsKeyPrefix().'from_address';
    }

    public static function purposeFromNameKey(MailPurpose $purpose): string
    {
        return $purpose->settingsKeyPrefix().'from_name';
    }

    public static function purposeReplyToKey(MailPurpose $purpose): string
    {
        return $purpose->settingsKeyPrefix().'reply_to';
    }

    /**
     * The effective sender identity for one mail purpose: its own override
     * when set, otherwise the global sender (from.address/from.name) — the
     * same fallback #377 requires ("Unset purpose-specific values fall back
     * to the global sender without duplicated required configuration").
     * Reply-To has no global fallback: it is either explicitly set for this
     * purpose or absent, since there is no platform-wide Reply-To concept.
     *
     * @return array{address: string, name: string, reply_to: ?string}
     */
    public function effectiveIdentity(MailPurpose $purpose): array
    {
        $address = $this->nullableString(self::purposeFromAddressKey($purpose))
            ?? (string) $this->settings->get(self::FROM_ADDRESS_KEY);

        $name = $this->nullableString(self::purposeFromNameKey($purpose))
            ?? $this->nullableString(self::FROM_NAME_KEY)
            ?? $this->system->productName();

        return [
            'address' => $address,
            'name' => $name,
            'reply_to' => $this->nullableString(self::purposeReplyToKey($purpose)),
        ];
    }

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
