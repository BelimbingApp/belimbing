<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Base\Locale\Contracts;

interface CurrencyDisplayService
{
    public function format(int|float $amount, string $currencyCode, ?int $precision = null): string;
}
