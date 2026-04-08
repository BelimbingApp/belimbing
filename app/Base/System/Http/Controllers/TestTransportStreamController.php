<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Base\System\Http\Controllers;

use App\Modules\Core\AI\Models\ChatTurn;
use App\Modules\Core\AI\Models\ChatTurnEvent;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class TestTransportStreamController
{
    public function __invoke(Request $request): StreamedResponse
    {
        $turnId = (string) $request->query('turn_id', '');
        $speed = max(1, (int) $request->query('speed', '1'));

        $turn = ChatTurn::query()->find($turnId);

        if ($turn === null) {
            return response()->stream(function (): void {
                echo json_encode(['error' => 'Turn not found'], JSON_THROW_ON_ERROR) . "\n";
                $this->flushOutput();
            }, 404, $this->streamHeaders());
        }

        if ((int) $turn->acting_for_user_id !== (int) auth()->id()) {
            return response()->stream(function (): void {
                echo json_encode(['error' => 'Forbidden'], JSON_THROW_ON_ERROR) . "\n";
                $this->flushOutput();
            }, 403, $this->streamHeaders());
        }

        $events = ChatTurnEvent::query()
            ->where('turn_id', $turnId)
            ->orderBy('seq')
            ->get();

        return response()->stream(function () use ($events, $speed): void {
            set_time_limit(0);

            $previousTimestamp = null;

            foreach ($events as $event) {
                if (connection_aborted()) {
                    return;
                }

                $currentTimestamp = $event->created_at;
                $delayMs = 0;

                if ($previousTimestamp !== null && $currentTimestamp !== null) {
                    $delayMs = max(0, $previousTimestamp->diffInMilliseconds($currentTimestamp));
                }

                $pacedDelayMs = $speed > 1 ? (int) ($delayMs / $speed) : $delayMs;

                if ($pacedDelayMs > 0) {
                    usleep($pacedDelayMs * 1000);
                }

                $payload = $event->toSsePayload();
                $payload['_replay_delay_ms'] = $delayMs;
                $payload['_paced_delay_ms'] = $pacedDelayMs;

                echo json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . "\n";
                $this->flushOutput();

                $previousTimestamp = $currentTimestamp;
            }

            if (!connection_aborted()) {
                echo json_encode(['_stream_complete' => true], JSON_THROW_ON_ERROR) . "\n";
                $this->flushOutput();
            }
        }, 200, $this->streamHeaders());
    }

    /**
     * @return array<string, string>
     */
    private function streamHeaders(): array
    {
        return [
            'Content-Type' => 'application/x-ndjson; charset=UTF-8',
            'Cache-Control' => 'no-cache',
            'Connection' => 'keep-alive',
            'X-Accel-Buffering' => 'no',
        ];
    }

    private function flushOutput(): void
    {
        if (ob_get_level() > 0) {
            ob_flush();
        }

        flush();
    }
}
