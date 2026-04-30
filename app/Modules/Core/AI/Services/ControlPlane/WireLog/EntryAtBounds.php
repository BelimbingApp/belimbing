<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\AI\Services\ControlPlane\WireLog;

/**
 * @internal
 *
 * Shared helpers for deriving the first/last timestamp from an entry window.
 */
final class EntryAtBounds
{
    /**
     * @param  list<array<string, mixed>>  $entries
     */
    public static function firstAt(array $entries): ?string
    {
        foreach ($entries as $entry) {
            if (is_string($entry['at'] ?? null)) {
                return $entry['at'];
            }
        }

        return null;
    }

    /**
     * @param  list<array<string, mixed>>  $entries
     */
    public static function lastAt(array $entries): ?string
    {
        $last = null;

        foreach ($entries as $entry) {
            if (is_string($entry['at'] ?? null)) {
                $last = $entry['at'];
            }
        }

        return $last;
    }
}
