<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Base\System\Http\Controllers;

use App\Modules\Core\AI\Models\ChatTurn;
use App\Modules\Core\AI\Models\ChatTurnEvent;
use Illuminate\Database\Eloquent\Collection;
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
            return $this->ndjsonErrorStream(404, 'Turn not found');
        }

        if ((int) $turn->acting_for_user_id !== (int) auth()->id()) {
            return $this->ndjsonErrorStream(403, 'Forbidden');
        }

        $events = ChatTurnEvent::query()
            ->where('turn_id', $turnId)
            ->orderBy('seq')
            ->get();

        return response()->stream(function () use ($events, $speed): void {
            $this->writeReplayStream($events, $speed);
        }, 200, $this->streamHeaders());
    }

    /**
     * @param  Collection<int, ChatTurnEvent>  $events
     */
    private function writeReplayStream(Collection $events, int $speed): void
    {
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

            echo json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)."\n";
            $this->flushOutput();

            $previousTimestamp = $currentTimestamp;
        }

        if (! connection_aborted()) {
            echo json_encode(['_stream_complete' => true], JSON_THROW_ON_ERROR)."\n";
            $this->flushOutput();
        }
    }

    private function ndjsonErrorStream(int $status, string $message): StreamedResponse
    {
        return response()->stream(function () use ($message): void {
            echo json_encode(['error' => $message], JSON_THROW_ON_ERROR)."\n";
            $this->flushOutput();
        }, $status, $this->streamHeaders());
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
