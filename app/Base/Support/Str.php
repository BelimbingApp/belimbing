<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Base\Support;

final class Str
{
    /**
     * Mask the middle of a string while preserving its original length.
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
}
