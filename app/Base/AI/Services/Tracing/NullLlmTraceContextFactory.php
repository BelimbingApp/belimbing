<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Base\AI\Services\Tracing;

use App\Base\AI\Contracts\Tracing\LlmTraceContextFactory;
use Illuminate\Support\Str;

final class NullLlmTraceContextFactory implements LlmTraceContextFactory
{
    /**
     * @param  array<string, mixed>  $metadata
     */
    public function start(string $source, array $metadata = []): LlmTraceContext
    {
        return new LlmTraceContext(
            correlationId: 'trace_'.$this->safeSource($source).'_'.Str::random(12),
            source: $source,
            metadata: $metadata,
        );
    }

    private function safeSource(string $source): string
    {
        $safe = preg_replace('/\W+/', '_', $source) ?: 'llm';

        return trim($safe, '_') ?: 'llm';
    }
}
