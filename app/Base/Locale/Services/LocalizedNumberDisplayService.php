<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Base\Locale\Services;

use App\Base\Locale\Contracts\LocaleContext;
use App\Base\Locale\Contracts\NumberDisplayService;
use Illuminate\Support\Number;

class LocalizedNumberDisplayService implements NumberDisplayService
{
    public function __construct(
        private readonly LocaleContext $localeContext,
    ) {}

    public function format(int|float $number, ?int $precision = null, ?int $maxPrecision = null): string
    {
        return (string) Number::format(
            $number,
            precision: $precision,
            maxPrecision: $maxPrecision,
            locale: $this->localeContext->forNumber(),
        );
    }

    public function formatInteger(int|float $number): string
    {
        return $this->format($number, 0, 0);
    }

    public function abbreviate(int|float $number, int $precision = 0, ?int $maxPrecision = null): string
    {
        return (string) Number::withLocale(
            $this->localeContext->forNumber(),
            fn () => Number::abbreviate($number, $precision, $maxPrecision),
        );
    }
}
