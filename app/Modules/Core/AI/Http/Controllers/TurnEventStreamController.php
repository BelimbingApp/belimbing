<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\AI\Http\Controllers;

use App\Modules\Core\AI\Models\ChatTurn;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * SSE endpoint for replaying and following a turn's durable event stream.
 *
 * Serves two use cases:
 * 1. **Resume after disconnect** — client provides `after_seq` to skip
 *    events it already received; the endpoint replays the gap then follows.
 * 2. **Full replay on page load** — client provides `after_seq=0` (default)
 *    to receive the entire history, then follows if the turn is still active.
 *
 * For active turns the connection stays open and polls the DB at a short
 * interval until the turn reaches a terminal state. This is intentionally
 * simple (no pub/sub infra required) and adequate for single-user turns.
 */
class TurnEventStreamController
{
    private const POLL_INTERVAL_US = 500_000; // 500 ms

    private const MAX_IDLE_SECONDS = 300;     // close after 5 min of no new events

    /**
     * Stream turn events as SSE.
     */
    public function __invoke(Request $request, string $turnId): StreamedResponse|Response
    {
        $turn = ChatTurn::query()->find($turnId);

        if ($turn === null) {
            return response('Turn not found', 404);
        }

        // Ownership check: turn must belong to the authenticated user
        if ((int) $turn->acting_for_user_id !== (int) auth()->id()) {
            return response('Forbidden', 403);
        }

        $afterSeq = (int) $request->query('after_seq', '0');

        return new StreamedResponse(function () use ($turn, $afterSeq): void {
            $lastSeq = $afterSeq;
            $idleStart = null;

            // Phase 1: Replay persisted events
            $events = $turn->eventsAfter($lastSeq)->get();

            foreach ($events as $event) {
                $this->emitTurnEvent($event->toSsePayload());
                $lastSeq = $event->seq;
            }

            // If the turn is already terminal, close immediately after replay
            $turn->refresh();

            if ($turn->isTerminal()) {
                $this->emitMeta('stream_end', ['reason' => 'turn_terminal', 'status' => $turn->status->value]);

                return;
            }

            // Phase 2: Poll for new events until the turn reaches terminal state
            while (true) {
                usleep(self::POLL_INTERVAL_US);

                if (connection_aborted()) {
                    return;
                }

                $newEvents = $turn->eventsAfter($lastSeq)->get();

                if ($newEvents->isNotEmpty()) {
                    $idleStart = null;

                    foreach ($newEvents as $event) {
                        $this->emitTurnEvent($event->toSsePayload());
                        $lastSeq = $event->seq;
                    }

                    // Check for terminal after emitting
                    $turn->refresh();

                    if ($turn->isTerminal()) {
                        $this->emitMeta('stream_end', ['reason' => 'turn_terminal', 'status' => $turn->status->value]);

                        return;
                    }
                } else {
                    // No new events — track idle time for safety timeout
                    $idleStart ??= time();

                    if ((time() - $idleStart) >= self::MAX_IDLE_SECONDS) {
                        $this->emitMeta('stream_end', ['reason' => 'idle_timeout']);

                        return;
                    }

                    // Emit keepalive comment to prevent proxy/browser timeout
                    echo ": keepalive\n\n";
                    $this->flush();
                }
            }
        }, 200, [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache, no-transform',
            'Connection' => 'keep-alive',
            'X-Accel-Buffering' => 'no',
        ]);
    }

    /**
     * Emit a turn event as SSE.
     *
     * @param  array<string, mixed>  $payload
     */
    private function emitTurnEvent(array $payload): void
    {
        $eventType = $payload['event_type'] ?? 'turn_event';

        echo "event: {$eventType}\n";
        echo 'data: '.json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n\n";
        $this->flush();
    }

    /**
     * Emit a stream-level meta event (not a turn event).
     *
     * @param  array<string, mixed>  $data
     */
    private function emitMeta(string $type, array $data): void
    {
        echo "event: meta\n";
        echo 'data: '.json_encode(['type' => $type, ...$data], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n\n";
        $this->flush();
    }

    private function flush(): void
    {
        if (ob_get_level() > 0) {
            ob_flush();
        }

        flush();
    }
}
