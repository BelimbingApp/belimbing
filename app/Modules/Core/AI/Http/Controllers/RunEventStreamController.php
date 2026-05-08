<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\AI\Http\Controllers;

use App\Modules\Core\AI\Models\AiRun;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;

/**
 * HTTP endpoint for replaying a run's persisted event stream.
 *
 * Returns all events after `after_seq` as a JSON array. The direct
 * streaming endpoint handles fresh chat runs; this endpoint covers replay
 * and gap-fill for already-persisted events:
 *
 * 1. **Page-load replay** — client fetches missed events for an active run.
 * 2. **Reconnect gap-fill** — client provides `after_seq` to fetch
 *    events missed during a brief disconnect before polling resumes.
 */
class RunEventStreamController
{
    /**
     * Return run events as JSON for replay.
     */
    public function __invoke(Request $request, string $runId): JsonResponse|Response
    {
        $turn = AiRun::query()->find($runId);

        if ($turn === null) {
            return response('Run not found', 404);
        }

        if ((int) $turn->acting_for_user_id !== (int) Auth::id()) {
            return response('Forbidden', 403);
        }

        $afterSeq = (int) $request->query('after_seq', '0');

        $events = $turn->eventsAfter($afterSeq)
            ->get()
            ->map(fn ($event) => $event->toSsePayload())
            ->values();

        return response()->json([
            'events' => $events,
            'run_id' => $turn->id,
            'status' => $turn->status->value,
            'current_phase' => $turn->current_phase?->value,
            'current_label' => $turn->current_label,
            'started_at' => $turn->started_at?->toIso8601String(),
            'created_at' => $turn->created_at?->toIso8601String(),
            'cancel_requested_at' => $turn->cancel_requested_at?->toIso8601String(),
        ]);
    }
}
