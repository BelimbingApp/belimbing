<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Base\System\Http\Controllers;

use App\Base\System\Events\ReverbTestMessageOccurred;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TestReverbDispatchController
{
    public const BURST_SIZE = 5;

    public const BURST_INTERVAL_MICROSECONDS = 250000;

    public function __invoke(Request $request): JsonResponse
    {
        $userId = (int) $request->user()->getAuthIdentifier();

        foreach (range(1, self::BURST_SIZE) as $sequence) {
            ReverbTestMessageOccurred::dispatch($userId, [
                'connection' => 'reverb',
                'transport' => 'websocket',
                'sequence' => $sequence,
                'message' => __('Reverb event #:sequence reached the browser.', ['sequence' => $sequence]),
                'sent_at' => now()->toIso8601String(),
            ]);

            if ($sequence < self::BURST_SIZE) {
                usleep(self::BURST_INTERVAL_MICROSECONDS);
            }
        }

        return response()->json([
            'ok' => true,
            'message' => __('Dispatched :count Reverb test events.', ['count' => self::BURST_SIZE]),
        ]);
    }
}
