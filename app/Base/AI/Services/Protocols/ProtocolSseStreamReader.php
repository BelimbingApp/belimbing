<?php
namespace App\Base\AI\Services\Protocols;

use App\Base\AI\Contracts\LlmTransportTap;
use App\Base\AI\DTO\ChatRequest;
use Generator;
use Psr\Http\Message\StreamInterface;

/**
 * @internal
 *
 * Reads newline-delimited SSE chunks from a PSR-7 stream with timeout handling.
 */
final class ProtocolSseStreamReader
{
    /**
     * @param  callable(\RuntimeException): bool  $isStreamReadTimeout
     */
    public function __construct(
        private $isStreamReadTimeout,
    ) {}

    /**
     * @return Generator<int, string>
     */
    public function linesFromStream(
        ChatRequest $request,
        StreamInterface $stream,
        ?LlmTransportTap $transportTap,
        bool $flushTrailingBuffer,
    ): Generator {
        $buffer = '';
        $firstByteRecorded = false;

        while (! $stream->eof()) {
            try {
                $chunk = $stream->read(8192);
            } catch (\RuntimeException $e) {
                $timeoutOutcome = $this->sseStreamReadTimeoutOutcome($request, $stream, $e);

                if ($timeoutOutcome === 'rethrow') {
                    throw $e;
                }

                if ($timeoutOutcome === 'stop') {
                    return;
                }

                yield '';

                continue;
            }

            if ($chunk === '') {
                continue;
            }

            if (! $firstByteRecorded) {
                $transportTap?->firstByte();
                $firstByteRecorded = true;
            }

            yield from $this->yieldSseLinesFromBufferAppend($chunk, $buffer, $transportTap);
        }

        yield from $this->yieldFlushTrailingSseBuffer($buffer, $flushTrailingBuffer, $transportTap);
    }

    /**
     * @return 'rethrow'|'yield_empty'|'stop'
     */
    private function sseStreamReadTimeoutOutcome(
        ChatRequest $request,
        StreamInterface $stream,
        \RuntimeException $e,
    ): string {
        if (! ($this->isStreamReadTimeout)($e)) {
            return 'rethrow';
        }

        if ($request->isCancelRequested()) {
            $stream->close();

            return 'stop';
        }

        return 'yield_empty';
    }

    /**
     * @return Generator<int, string>
     */
    private function yieldSseLinesFromBufferAppend(
        string $chunk,
        string &$buffer,
        ?LlmTransportTap $transportTap,
    ): Generator {
        $buffer .= $chunk;
        $lines = explode("\n", $buffer);
        $buffer = (string) array_pop($lines);

        foreach ($lines as $line) {
            $trimmedLine = trim($line);
            $transportTap?->streamLine($trimmedLine);

            yield $trimmedLine;
        }
    }

    /**
     * @return Generator<int, string>
     */
    private function yieldFlushTrailingSseBuffer(
        string $buffer,
        bool $flushTrailingBuffer,
        ?LlmTransportTap $transportTap,
    ): Generator {
        if (! $flushTrailingBuffer || trim($buffer) === '') {
            return;
        }

        foreach (explode("\n", $buffer) as $line) {
            $trimmedLine = trim($line);
            $transportTap?->streamLine($trimmedLine);

            yield $trimmedLine;
        }
    }
}
