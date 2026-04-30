<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\AI\Values;

/**
 * Auditable cents-per-token rate resolved for a model at call time.
 */
final readonly class ResolvedRate
{
    public function __construct(
        public ?string $provider,
        public string $model,
        public string $source,
        public ?string $version,
        public string $inputCentsPerToken,
        public ?string $cachedInputCentsPerToken,
        public string $outputCentsPerToken,
    ) {}
}
