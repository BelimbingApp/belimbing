<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Base\Foundation\ValueObjects;

use InvalidArgumentException;

final readonly class Money
{
    public function __construct(
        public int $minorAmount,
        public string $currencyCode,
    ) {
        if ($minorAmount < 0) {
            throw new InvalidArgumentException('Money amount cannot be negative.');
        }

        if (! preg_match('/^[A-Z]{3}$/', $currencyCode)) {
            throw new InvalidArgumentException('Currency code must be an uppercase ISO 4217 code.');
        }
    }

    public static function fromDecimalString(?string $amount, string $currencyCode): ?self
    {
        if ($amount === null || trim($amount) === '') {
            return null;
        }

        $amount = trim($amount);

        if (! preg_match('/^\d+(\.\d{1,2})?$/', $amount)) {
            throw new InvalidArgumentException('Money amount must be a decimal with up to two fractional digits.');
        }

        [$whole, $fraction] = array_pad(explode('.', $amount, 2), 2, '0');
        $minorAmount = ((int) $whole * 100) + (int) str_pad(substr($fraction, 0, 2), 2, '0');

        return new self($minorAmount, strtoupper($currencyCode));
    }

    public static function format(?int $minorAmount, string $currencyCode): string
    {
        if ($minorAmount === null) {
            return '-';
        }

        $whole = intdiv($minorAmount, 100);
        $fraction = str_pad((string) ($minorAmount % 100), 2, '0', STR_PAD_LEFT);

        return strtoupper($currencyCode).' '.number_format($whole).'.'.$fraction;
    }

    public static function formatInput(?int $minorAmount): ?string
    {
        if ($minorAmount === null) {
            return null;
        }

        $whole = intdiv($minorAmount, 100);
        $fraction = str_pad((string) ($minorAmount % 100), 2, '0', STR_PAD_LEFT);

        return $whole.'.'.$fraction;
    }
}
