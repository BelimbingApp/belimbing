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

it('formats negative minor units with the sign before the whole part', function (): void {
    expect(Money::format(-150, 'USD'))->toBe('USD -1.50')
        ->and(Money::format(-50, 'USD'))->toBe('USD -0.50')
        ->and(Money::format(-100, 'USD'))->toBe('USD -1.00')
        ->and(Money::format(-123456, 'USD'))->toBe('USD -1,234.56')
        ->and(Money::formatInput(-150))->toBe('-1.50')
        ->and(Money::formatInput(-50))->toBe('-0.50');
});
