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
}
