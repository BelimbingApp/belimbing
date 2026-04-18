<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Base\AI\DTO;

final readonly class ProviderRequestMapping
{
    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, string>  $headers
     * @param  list<ProviderControlAdjustment>  $controlAdjustments
     */
    public function __construct(
        public array $payload,
        public array $headers = [],
        public array $controlAdjustments = [],
    ) {}

    /**
     * @return array{control_adjustments: list<array<string, mixed>>}|null
     */
    public function meta(): ?array
    {
        if ($this->controlAdjustments === []) {
            return null;
        }

        return [
            'control_adjustments' => array_map(
                static fn (ProviderControlAdjustment $adjustment): array => $adjustment->toArray(),
                $this->controlAdjustments,
            ),
        ];
    }
}
