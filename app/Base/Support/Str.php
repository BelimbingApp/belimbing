<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Base\Support;

final class Str
{
    /**
     * Mask the middle of a string while preserving its original length.
     *
     * Example:
     * - input:  'not-required' (prefix=7, suffix=4)
     * - output: 'no******ired'
     *
     * For strings shorter than the minimum display length, the full string is
     * masked. Otherwise, the method preserves the requested prefix and suffix
     * where possible and pads the hidden middle with the mask character.
     * When both ends cannot fit, the prefix is preserved first.
     */
    public static function maskMiddle(
        ?string $value,
        int $prefixLength = 1,
        int $suffixLength = 1,
        string $maskCharacter = '*',
        int $minimumDisplayLength = 4,
        int $minimumMaskLength = 2,
    ): ?string {
        if ($value === null || $value === '') {
            return $value;
        }

        $maskChar = ($maskCharacter !== '' && $maskCharacter[0] !== "\0")
            ? $maskCharacter[0]
            : '*';

        // ASCII fast path: strlen is O(1) in PHP; skip mb_* when all bytes
        // are single-character (true for virtually all API keys and tokens).
        $length = mb_strlen($value);
        $ascii = \strlen($value) === $length;

        if ($length < max(1, $minimumDisplayLength)) {
            return str_repeat($maskChar, $length);
        }

        // The masked region must cover at least half the string so the
        // secret is genuinely hidden, and never fewer than minimumMaskLength.
        $minMask = max(max(1, $minimumMaskLength), intdiv($length + 1, 2));
        $visibleBudget = max(0, $length - $minMask);

        // Allocate suffix first so at least some trailing chars are always
        // visible (useful for quick visual verification), then give the
        // remainder to the prefix.
        $suf = min(max(0, $suffixLength), $visibleBudget);
        $pre = min(max(0, $prefixLength), max(0, $visibleBudget - $suf));

        $maskedChars = str_repeat($maskChar, $length - $pre - $suf);
        $slice = $ascii ? substr(...) : mb_substr(...);

        return ($pre > 0 ? $slice($value, 0, $pre) : '')
            .$maskedChars
            .($suf > 0 ? $slice($value, -$suf) : '');
    }

    /**
     * Truncate a string to a maximum length with an optional tail suffix.
     *
     * Example:
     * - input:  'hello world' (max=5)
     * - output: 'hello…'
     *
     * Returns the original string untouched when it is at or below the limit.
     */
    public static function truncate(string $value, int $maxChars, string $suffix = '…'): string
    {
        return mb_strlen($value) <= $maxChars
            ? $value
            : mb_substr($value, 0, $maxChars).$suffix;
    }

    /**
     * Build a short leading preview of a string.
     *
     * Example:
     * - input:  'Browser runner returned invalid JSON payload' (max=20)
     * - output: 'Browser runner retu…'
     *
     * This is a readability-oriented alias for `truncate()` when the caller is
     * producing a UI or log preview rather than enforcing a hard content limit.
     */
    public static function preview(string $value, int $maxChars = 200, string $suffix = '…'): string
    {
        return self::truncate($value, $maxChars, $suffix);
    }

    /**
     * Truncate a string and append a "[truncated, N chars]" audit marker.
     *
     * Example:
     * - input:  'hello world' (max=5)
     * - output: 'hello[truncated, 11 chars]'
     *
     * Used where the caller needs to record the original length after clipping,
     * such as audit mutation diffs. Returns the original string when under the limit.
     */
    public static function truncateWithCount(string $value, int $maxChars): string
    {
        $length = mb_strlen($value);

        return $length <= $maxChars
            ? $value
            : mb_substr($value, 0, $maxChars).'[truncated, '.$length.' chars]';
    }

    /**
     * Extract a fixed-width snippet centered on a byte/character position.
     *
     * Example:
     * - input:  'aaaaaMATCHzzzzz' (around=5, width=9)
     * - output: '…aMATCHzz…'
     *
     * Appends or prepends the edge character when the window does not reach the
     * start or end of the original string, so callers always know context is missing.
     * Returns the full string unchanged when it fits within the window.
     *
     * @param  int  $around  Character offset to center the window on
     */
    public static function snippetAround(
        string $value,
        int $around,
        int $width = 120,
        string $edge = '…',
    ): string {
        $length = mb_strlen($value);

        if ($length <= $width) {
            return $value;
        }

        $half = intdiv($width, 2);
        $start = max(0, $around - $half);
        $end = min($length, $start + $width);

        if ($end === $length) {
            $start = max(0, $end - $width);
        }

        $snippet = mb_substr($value, $start, $end - $start);

        return ($start > 0 ? $edge : '')
            .$snippet
            .($end < $length ? $edge : '');
    }

    /**
     * Return the part of a string that comes after a known prefix.
     *
     * Example:
     * - input:  '/guide authz' (prefix='/guide')
     * - output: 'authz'
     *
     * When the prefix is missing, the original string is returned. Trimming is
     * enabled by default because command-style callers typically want the
     * remaining argument payload without surrounding whitespace.
     */
    public static function afterPrefix(string $value, string $prefix, bool $trim = true): string
    {
        $result = str_starts_with($value, $prefix)
            ? substr($value, strlen($prefix))
            : $value;

        return $trim ? trim($result) : $result;
    }

    /**
     * Normalise a human-readable name into a system code.
     *
     * Example:
     * - input:  'Northwind Holdings Ltd.'
     * - output: 'northwind_holdings_ltd'
     *
     * Applies slug conversion followed by lower-casing so the result is safe
     * to use as a config key, database code, or identifier.
     *
     * @param  string  $separator  Word separator — underscore for snake codes, hyphen for slugs
     */
    public static function code(string $value, string $separator = '_'): string
    {
        return mb_strtolower(\Illuminate\Support\Str::slug($value, $separator));
    }

    /**
     * Convert a PascalCase string to kebab-case.
     *
     * Example:
     * - input:  'SbGroup'
     * - output: 'sb-group'
     *
     * Intended for namespace or class-name segments that need a filesystem-safe
     * kebab-case representation without the broader normalization performed by
     * slug generation.
     */
    public static function pascalToKebab(string $value): string
    {
        return strtolower((string) preg_replace('/(?<!^)[A-Z]/', '-$0', $value));
    }
}
