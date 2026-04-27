<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\AI\Services\ControlPlane;

final class WireLogReadableFormatter
{
    /**
     * @param  list<array<string, mixed>>  $entries
     * @return array{overview: array<string, int>, entries: list<array<string, mixed>>}
     */
    public function format(array $entries): array
    {
        $overview = [];

        foreach ($entries as $entry) {
            $type = is_string($entry['type'] ?? null) && $entry['type'] !== ''
                ? $entry['type']
                : 'unknown';

            $overview[$type] = ($overview[$type] ?? 0) + 1;
        }

        ksort($overview);

        return [
            'overview' => $overview,
            'entries' => $entries,
        ];
    }
}
