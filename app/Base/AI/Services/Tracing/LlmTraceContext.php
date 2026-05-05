<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Base\AI\Services\Tracing;

use App\Base\AI\Contracts\LlmTransportTap;

final readonly class LlmTraceContext
{
    /**
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public string $correlationId,
        public string $source,
        public ?LlmTransportTap $transportTap = null,
        public array $metadata = [],
    ) {}
}
