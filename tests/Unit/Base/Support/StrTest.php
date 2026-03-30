<?php

use App\Base\Support\Str;
use Tests\TestCase;

uses(TestCase::class);

it('masks a four character string with visible ends', function (): void {
    expect(Str::maskMiddle('abcz'))->toBe('a**z');
});

it('preserves the original string length when masking the middle', function (): void {
    $value = 'sk-test-1234567890abcd';
    $masked = Str::maskMiddle($value, 7, 4);

    expect($masked)->toBe('sk-test***********abcd')
        ->and(mb_strlen($masked ?? ''))->toBe(mb_strlen($value));
});

it('fully masks strings shorter than the minimum display length', function (): void {
    expect(Str::maskMiddle('abc'))->toBe('***');
});

it('masks at least half the string for short values', function (): void {
    $value = 'not-required';
    $masked = Str::maskMiddle($value, 7, 4);

    expect($masked)->toBe('no******ired')
        ->and(mb_strlen($masked ?? ''))->toBe(mb_strlen($value));
});

it('returns null and empty strings unchanged', function (): void {
    expect(Str::maskMiddle(null))->toBeNull()
        ->and(Str::maskMiddle(''))->toBe('');
});
