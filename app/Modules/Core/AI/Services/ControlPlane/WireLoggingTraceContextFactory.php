<?php
namespace App\Modules\Core\AI\Services\ControlPlane;

use App\Base\AI\Contracts\Tracing\LlmTraceContextFactory;
use App\Base\AI\Services\Tracing\LlmTraceContext;
use Illuminate\Support\Str;

final class WireLoggingTraceContextFactory implements LlmTraceContextFactory
{
    public function __construct(
        private readonly WireLogger $wireLogger,
    ) {}

    /**
     * @param  array<string, mixed>  $metadata
     */
    public function start(string $source, array $metadata = []): LlmTraceContext
    {
        $correlationId = 'trace_'.$this->safeSource($source).'_'.Str::random(12);

        if (! $this->wireLogger->enabled()) {
            return new LlmTraceContext(
                correlationId: $correlationId,
                source: $source,
                metadata: $metadata,
            );
        }

        $this->wireLogger->append($correlationId, [
            'type' => 'llm.trace_start',
            'source' => $source,
            'correlation_id' => $correlationId,
            'metadata' => $metadata,
        ]);

        return new LlmTraceContext(
            correlationId: $correlationId,
            source: $source,
            transportTap: new WireLoggingTransportTap($this->wireLogger, $correlationId),
            metadata: $metadata,
        );
    }

    private function safeSource(string $source): string
    {
        $safe = preg_replace('/\W+/', '_', $source) ?: 'llm';

        return trim($safe, '_') ?: 'llm';
    }
}
