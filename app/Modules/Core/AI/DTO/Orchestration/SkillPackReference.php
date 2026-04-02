<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\AI\DTO\Orchestration;

/**
 * A reference document bundled within a skill pack.
 */
final readonly class SkillPackReference
{
    /**
     * @param  string  $title  Reference title
     * @param  string  $path  File path or URI to the reference
     * @param  string|null  $summary  Brief description of what the reference contains
     */
    public function __construct(
        public string $title,
        public string $path,
        public ?string $summary = null,
    ) {}

    /**
     * @return array{title: string, path: string, summary: string|null}
     */
    public function toArray(): array
    {
        return [
            'title' => $this->title,
            'path' => $this->path,
            'summary' => $this->summary,
        ];
    }
}
