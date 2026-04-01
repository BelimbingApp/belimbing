<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\AI\DTO;

use App\Modules\Core\AI\Enums\MemoryFileType;
use App\Modules\Core\AI\Enums\MemoryRetrievalBasis;

/**
 * A single result from memory retrieval.
 *
 * Carries the source citation, score explanation, and trust classification
 * so agents and operators can assess provenance and confidence.
 */
final readonly class MemorySearchResult
{
    /**
     * @param  string  $sourcePath  Relative path within workspace
     * @param  string  $heading  Section heading
     * @param  string  $snippet  Content excerpt
     * @param  float  $score  Combined retrieval score (0.0–1.0)
     * @param  MemoryRetrievalBasis  $basis  How this result was found
     * @param  MemoryFileType  $sourceType  Trust classification of the source
     */
    public function __construct(
        public string $sourcePath,
        public string $heading,
        public string $snippet,
        public float $score,
        public MemoryRetrievalBasis $basis,
        public MemoryFileType $sourceType,
    ) {}

    /**
     * @return array{source: string, heading: string, snippet: string, score: float, basis: string, type: string}
     */
    public function toArray(): array
    {
        return [
            'source' => $this->sourcePath,
            'heading' => $this->heading,
            'snippet' => $this->snippet,
            'score' => $this->score,
            'basis' => $this->basis->value,
            'type' => $this->sourceType->value,
        ];
    }
}
