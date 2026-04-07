<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Base\System\Http\Controllers;

use App\Base\System\Events\ReverbTestMessageOccurred;
use App\Base\System\Support\CodingAgentTransportSimulator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TestReverbDispatchController
{
    public const TURN_COUNT = 3;

    public const EVENT_COUNT = self::TURN_COUNT * CodingAgentTransportSimulator::EVENTS_PER_TURN;

    public const BURST_INTERVAL_MICROSECONDS = 150000;

    public function __construct(
        private readonly CodingAgentTransportSimulator $simulator,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $userId = (int) $request->user()->getAuthIdentifier();
        $payloads = $this->simulator->makeTurnBurstPayloads(self::TURN_COUNT);

        foreach ($payloads as $index => $payload) {
            ReverbTestMessageOccurred::dispatch($userId, [
                ...$payload,
                'connection' => 'reverb',
                'transport' => 'websocket',
                'sent_at' => now()->toIso8601String(),
            ]);

            if ($index < count($payloads) - 1) {
                usleep(self::BURST_INTERVAL_MICROSECONDS);
            }
        }

        return response()->json([
            'ok' => true,
            'message' => __('Dispatched :events Reverb coding-agent events across :turns turns.', [
                'events' => self::EVENT_COUNT,
                'turns' => self::TURN_COUNT,
            ]),
        ]);
    }
}
