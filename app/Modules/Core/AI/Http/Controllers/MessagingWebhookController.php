<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\AI\Http\Controllers;

use App\Modules\Core\AI\Jobs\ProcessInboundSignalJob;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Webhook endpoint for inbound messaging channel events.
 *
 * Accepts POST requests from external platforms (WhatsApp, Telegram,
 * Slack, Email relay, etc.), quickly serializes the request into a
 * queue job, and returns a 202 Accepted response. All heavy processing
 * (normalization, routing, dispatch) happens asynchronously in the
 * ProcessInboundSignalJob.
 *
 * Route: POST /api/ai/messaging/webhook/{channel}/{accountId?}
 */
class MessagingWebhookController
{
    /**
     * Handle an inbound webhook request.
     *
     * @param  Request  $request  Raw inbound HTTP request from the channel platform
     * @param  string  $channel  Channel identifier from URL (e.g., 'email', 'whatsapp')
     * @param  int|null  $accountId  Optional channel account ID from URL
     */
    public function __invoke(Request $request, string $channel, ?int $accountId = null): JsonResponse
    {
        dispatch(ProcessInboundSignalJob::fromRequest($channel, $request, $accountId));

        return response()->json([
            'status' => 'accepted',
            'channel' => $channel,
        ], 202);
    }
}
