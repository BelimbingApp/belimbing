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

// ---------------------------------------------------------------------------
// truncate
// ---------------------------------------------------------------------------

it('truncate returns the string unchanged when under the limit', function (): void {
    expect(Str::truncate('hello', 10))->toBe('hello');
});

it('truncate clips at the limit and appends the suffix', function (): void {
    expect(Str::truncate('hello world', 5))->toBe('hello…');
});

it('truncate accepts a custom suffix', function (): void {
    expect(Str::truncate('hello world', 5, '...'))->toBe('hello...');
});

it('truncate returns the string unchanged when exactly at the limit', function (): void {
    expect(Str::truncate('hello', 5))->toBe('hello');
});

// ---------------------------------------------------------------------------
// preview
// ---------------------------------------------------------------------------

it('preview returns the string unchanged when under the limit', function (): void {
    expect(Str::preview('hello', 10))->toBe('hello');
});

it('preview truncates with the default suffix', function (): void {
    expect(Str::preview('hello world', 5))->toBe('hello…');
});

it('preview accepts a custom suffix', function (): void {
    expect(Str::preview('hello world', 5, '...'))->toBe('hello...');
});

// ---------------------------------------------------------------------------
// truncateWithCount
// ---------------------------------------------------------------------------

it('truncateWithCount returns the string unchanged when under the limit', function (): void {
    expect(Str::truncateWithCount('hello', 10))->toBe('hello');
});

it('truncateWithCount clips and appends the original length marker', function (): void {
    expect(Str::truncateWithCount('hello world', 5))->toBe('hello[truncated, 11 chars]');
});

// ---------------------------------------------------------------------------
// snippetAround
// ---------------------------------------------------------------------------

it('snippetAround returns the full string when it fits in the window', function (): void {
    expect(Str::snippetAround('short text', 0))->toBe('short text');
});

it('snippetAround centers the window on the match position', function (): void {
    $value = str_repeat('a', 60).'MATCH'.str_repeat('z', 60);
    $snippet = Str::snippetAround($value, 60, 20);

    expect($snippet)->toContain('MATCH')
        ->and(mb_strlen(str_replace('…', '', $snippet)))->toBeLessThanOrEqual(20);
});

it('snippetAround adds edge markers when the window does not reach the ends', function (): void {
    $value = str_repeat('x', 200);
    $snippet = Str::snippetAround($value, 100, 50);

    expect($snippet)->toStartWith('…')->toEndWith('…');
});

// ---------------------------------------------------------------------------
// afterPrefix
// ---------------------------------------------------------------------------

it('afterPrefix returns the part after the prefix and trims by default', function (): void {
    expect(Str::afterPrefix('/guide authz', '/guide'))->toBe('authz');
});

it('afterPrefix preserves surrounding spaces when trim is disabled', function (): void {
    expect(Str::afterPrefix('/guide authz  ', '/guide', false))->toBe(' authz  ');
});

it('afterPrefix returns the original string when the prefix is missing', function (): void {
    expect(Str::afterPrefix('authz', '/guide'))->toBe('authz');
});

// ---------------------------------------------------------------------------
// code
// ---------------------------------------------------------------------------

it('code converts a human-readable name to a lowercase underscore slug', function (): void {
    expect(Str::code('My Company'))->toBe('my_company');
});

it('code strips special characters and collapses spaces', function (): void {
    expect(Str::code('Northwind Holdings Ltd.'))->toBe('northwind_holdings_ltd');
});

it('code accepts a custom separator', function (): void {
    expect(Str::code('My Company', '-'))->toBe('my-company');
});

// ---------------------------------------------------------------------------
// pascalToKebab
// ---------------------------------------------------------------------------

it('pascalToKebab converts PascalCase segments to kebab-case', function (): void {
    expect(Str::pascalToKebab('SbGroup'))->toBe('sb-group');
});

it('pascalToKebab leaves single-word segments lowercase', function (): void {
    expect(Str::pascalToKebab('Qac'))->toBe('qac');
});
