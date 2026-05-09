<?php
namespace App\Base\Locale\Services;

use App\Base\Locale\Contracts\CurrencyDisplayService;
use App\Base\Locale\Contracts\LocaleContext;
use Illuminate\Support\Number;

class LocalizedCurrencyDisplayService implements CurrencyDisplayService
{
    public function __construct(
        private readonly LocaleContext $localeContext,
    ) {}

    public function format(int|float $amount, string $currencyCode, ?int $precision = null): string
    {
        return (string) Number::currency(
            $amount,
            in: strtoupper($currencyCode),
            locale: $this->localeContext->forNumber(),
            precision: $precision,
        );
    }
}
