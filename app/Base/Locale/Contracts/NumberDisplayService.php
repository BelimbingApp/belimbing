<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Base\Locale\Contracts;

interface NumberDisplayService
{
    public function format(int|float $number, ?int $precision = null, ?int $maxPrecision = null): string;

    public function formatInteger(int|float $number): string;

    public function abbreviate(int|float $number, int $precision = 0, ?int $maxPrecision = null): string;
}
