<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\AI\Services\ControlPlane\WireLog;

/**
 * @internal
 *
 * Groups raw wire-log entries into readable "sections" (events + grouped stream blocks).
 */
final class EntryGrouper
{
    public function __construct(
        private readonly EntryPresenter $entryPresenter,
        private readonly StreamAssembler $streamAssembler,
        private readonly \Closure $diffMs,
    ) {}

    /**
     * @param  list<array<string, mixed>>  $entries
     * @return list<array<string, mixed>>
     */
    public function groupEntries(array $entries): array
    {
        $sections = [];
        $streamBuffer = [];

        foreach ($entries as $entry) {
            $type = is_string($entry['type'] ?? null) ? $entry['type'] : '';

            if ($type === 'llm.stream_line') {
                $streamBuffer[] = $entry;

                continue;
            }

            if ($streamBuffer !== []) {
                $sections[] = $this->streamAssembler->buildStreamBlock($streamBuffer, $this->diffMs);
                $streamBuffer = [];
            }

            $sections[] = $this->entryPresenter->buildEvent($entry);
        }

        if ($streamBuffer !== []) {
            $sections[] = $this->streamAssembler->buildStreamBlock($streamBuffer, $this->diffMs);
        }

        return $sections;
    }
}
