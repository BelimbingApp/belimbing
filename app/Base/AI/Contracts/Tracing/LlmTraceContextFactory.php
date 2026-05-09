<?php
namespace App\Base\AI\Contracts\Tracing;

use App\Base\AI\Services\Tracing\LlmTraceContext;

interface LlmTraceContextFactory
{
    /**
     * @param  array<string, mixed>  $metadata
     */
    public function start(string $source, array $metadata = []): LlmTraceContext;
}
