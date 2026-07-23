<?php

namespace App\Base\DateTime\Services;

use App\Base\DateTime\Contracts\DateTimeDisplayService as DateTimeDisplayServiceContract;
use App\Base\DateTime\Enums\TimezoneMode;
use App\Base\Locale\Contracts\LocaleContext;
use Carbon\Carbon;
use IntlDateFormatter;

class DateTimeDisplayService implements DateTimeDisplayServiceContract
{
    private const FORMAT_DATETIME = 'datetime';

    private const FORMAT_DATE = 'date';

    private const FORMAT_TIME = 'time';

    private ?TimezoneMode $resolvedMode = null;

    private ?string $resolvedTimezone = null;

    public function __construct(
        private readonly TimezoneSettings $timezoneSettings,
        private readonly LocaleContext $localeContext,
    ) {}

    /**
     * {@inheritdoc}
     */
    public function formatDateTime(\DateTimeInterface|string|null $value): string
    {
        return $this->format($value, self::FORMAT_DATETIME);
    }

    /**
     * {@inheritdoc}
     */
    public function formatDate(\DateTimeInterface|string|null $value): string
    {
        return $this->format($value, self::FORMAT_DATE);
    }

    /**
     * {@inheritdoc}
     */
    public function formatTime(\DateTimeInterface|string|null $value): string
    {
        return $this->format($value, self::FORMAT_TIME);
    }

    /**
     * {@inheritdoc}
     */
    public function currentMode(): TimezoneMode
    {
        if ($this->resolvedMode !== null) {
            return $this->resolvedMode;
        }

        $user = auth()->user();

        if (! $user) {
            return $this->resolvedMode = TimezoneMode::COMPANY;
        }

        return $this->resolvedMode = $this->timezoneSettings->modeForUser((int) $user->id);
    }

    /**
     * {@inheritdoc}
     */
    public function currentTimezone(): string
    {
        if ($this->resolvedTimezone !== null) {
            return $this->resolvedTimezone;
        }

        return $this->resolvedTimezone = match ($this->currentMode()) {
            TimezoneMode::COMPANY => $this->currentCompanyTimezone(),
            TimezoneMode::UTC,
            TimezoneMode::LOCAL => 'UTC',
        };
    }

    /**
     * {@inheritdoc}
     */
    public function currentCompanyTimezone(): string
    {
        return $this->resolveCompanyTimezone();
    }

    /**
     * {@inheritdoc}
     */
    public function isLocalMode(): bool
    {
        return $this->currentMode() === TimezoneMode::LOCAL;
    }

    /**
     * {@inheritdoc}
     */
    public function isCompanyTimezoneExplicit(): bool
    {
        $user = auth()->user();

        if (! $user || ! $user->company_id) {
            return false;
        }

        return $this->timezoneSettings->explicitCompanyTimezone((int) $user->company_id) !== null;
    }

    /**
     * Resolve the company-level default timezone.
     *
     * Reads 'localization.timezone' from the company scope of the
     * authenticated user, then its declared UTC default.
     */
    private function resolveCompanyTimezone(): string
    {
        $user = auth()->user();

        return $this->timezoneSettings->companyTimezone(
            $user?->company_id !== null ? (int) $user->company_id : null,
        );
    }

    /**
     * Shared formatting logic for all three format methods.
     *
     * Company mode uses ICU IntlDateFormatter for locale-aware output.
     * UTC/Stored mode uses a fixed ISO-like pattern (Y-m-d H:i:s) — raw database representation.
     * Local mode emits a UTC ISO-8601 string for browser-side formatting.
     *
     * @param  \DateTimeInterface|string|null  $value  Raw datetime value
     * @param  string  $type  One of FORMAT_DATETIME, FORMAT_DATE, FORMAT_TIME
     */
    private function format(\DateTimeInterface|string|null $value, string $type): string
    {
        if ($value === null) {
            return '—';
        }

        $carbon = $value instanceof \DateTimeInterface
            ? Carbon::instance($value)
            : Carbon::parse($value);

        if ($this->isLocalMode()) {
            return $carbon->utc()->toIso8601String();
        }

        $carbon = $carbon->setTimezone($this->currentTimezone());

        return $this->currentMode() === TimezoneMode::UTC
            ? $carbon->format($this->storedFormat($type))
            : $this->formatWithIntl($carbon, $type);
    }

    /**
     * Format a Carbon instance using ICU IntlDateFormatter.
     *
     * Uses the locale from LocaleContext::forIntl() and the resolved
     * timezone for accurate regional formatting.
     */
    private function formatWithIntl(Carbon $carbon, string $type): string
    {
        $locale = $this->localeContext->forIntl();
        $timezone = $this->currentTimezone();
        [$dateType, $timeType] = $this->intlFormatTypes($type);

        $formatter = new IntlDateFormatter(
            $locale,
            $dateType,
            $timeType,
            $timezone,
        );

        $result = $formatter->format($carbon->getTimestamp());

        if ($result === false) {
            return $carbon->locale($this->localeContext->forCarbon())
                ->isoFormat($this->carbonFallbackFormat($type));
        }

        return $result;
    }

    /**
     * Map format type to IntlDateFormatter date/time type constants.
     *
     * @return array{int, int}
     */
    private function intlFormatTypes(string $type): array
    {
        return match ($type) {
            self::FORMAT_DATE => [IntlDateFormatter::SHORT, IntlDateFormatter::NONE],
            self::FORMAT_TIME => [IntlDateFormatter::NONE, IntlDateFormatter::SHORT],
            default => [IntlDateFormatter::SHORT, IntlDateFormatter::SHORT],
        };
    }

    /**
     * Fixed format patterns for Stored/UTC mode — raw database representation.
     */
    private function storedFormat(string $type): string
    {
        return match ($type) {
            self::FORMAT_DATE => 'Y-m-d',
            self::FORMAT_TIME => 'H:i:s',
            default => 'Y-m-d H:i:s',
        };
    }

    /**
     * Carbon isoFormat tokens as fallback when IntlDateFormatter is unavailable.
     */
    private function carbonFallbackFormat(string $type): string
    {
        return match ($type) {
            self::FORMAT_DATE => 'L',
            self::FORMAT_TIME => 'LT',
            default => 'L LT',
        };
    }
}
