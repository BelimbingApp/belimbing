<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Base\Support;

final class Json
{
    /**
     * Decode a JSON document into an associative array.
     *
     * Returns null for null, blank, invalid, or non-object/non-array payloads.
     *
     * @return array<mixed>|null
     */
    public static function decodeArray(?string $value): ?array
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * Extract top-level `{ ... }` spans by counting `{` / `}` depth only.
     *
     * Intended for recovering JSON object substrings from noisy text (e.g. LLM output) before
     * {@see self::decodeArray()}. This is not a JSON tokenizer: braces inside strings are not ignored.
     *
     * @return list<string>
     */
    public static function braceBoundedObjectCandidates(string $text): array
    {
        $out = [];
        $len = strlen($text);
        $i = 0;

        while ($i < $len) {
            if ($text[$i] !== '{') {
                $i++;

                continue;
            }

            $start = $i;
            $depth = 0;

            for ($j = $i; $j < $len; $j++) {
                $c = $text[$j];

                if ($c === '{') {
                    $depth++;
                } elseif ($c === '}') {
                    $depth--;

                    if ($depth === 0) {
                        $out[] = substr($text, $start, $j - $start + 1);
                        $i = $j + 1;

                        continue 2;
                    }
                }
            }

            $i++;
        }

        return $out;
    }
}
