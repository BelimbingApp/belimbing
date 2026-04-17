<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Base\AI\DTO;

final readonly class SamplingControls
{
    public function __construct(
        public ?float $temperature = null,
        public ?float $topP = null,
        public ?int $candidateCount = null,
        public ?float $presencePenalty = null,
        public ?float $frequencyPenalty = null,
    ) {}

    /**
     * @return array{temperature: ?float, top_p: ?float, candidate_count: ?int, presence_penalty: ?float, frequency_penalty: ?float}
     */
    public function toArray(): array
    {
        return [
            'temperature' => $this->temperature,
            'top_p' => $this->topP,
            'candidate_count' => $this->candidateCount,
            'presence_penalty' => $this->presencePenalty,
            'frequency_penalty' => $this->frequencyPenalty,
        ];
    }
}
