<?php
namespace App\Base\Locale\Contracts;

interface CurrencyDisplayService
{
    public function format(int|float $amount, string $currencyCode, ?int $precision = null): string;
}
