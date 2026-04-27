<?php

use App\Base\Foundation\ValueObjects\Money;

it('parses decimal strings into minor units without floats', function (): void {
    expect(Money::fromDecimalString('45.50', 'usd')?->minorAmount)->toBe(4550)
        ->and(Money::fromDecimalString('45', 'USD')?->minorAmount)->toBe(4500)
        ->and(Money::fromDecimalString('45.5', 'USD')?->minorAmount)->toBe(4550)
        ->and(Money::fromDecimalString(null, 'USD'))->toBeNull();
});

it('formats minor units for display and input', function (): void {
    expect(Money::format(123456, 'usd'))->toBe('USD 1,234.56')
        ->and(Money::formatInput(123456))->toBe('1234.56');
});
