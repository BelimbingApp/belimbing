<?php

namespace App\Modules\Core\AI\Http\Controllers;

use App\Modules\Core\AI\Enums\SignalAuthenticityStatus;
use App\Modules\Core\AI\Jobs\ProcessInboundSignalJob;
use App\Modules\Core\AI\Services\Messaging\ChannelAdapterRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Webhook endpoint for inbound messaging channel events.
 *
 * Accepts POST requests from external platforms (WhatsApp, Telegram,
 * Slack, Email relay, etc.), verifies authenticity through the channel
 * adapter, and only then serializes the request into a queue job.
 *
 * Fail-closed: requests that cannot be verified are rejected with 401
 * before any job is dispatched, preventing forged events from reaching
 * downstream processing.
 *
 * Route: POST /api/ai/messaging/webhook/{channel}/{accountId?}
 */
class MessagingWebhookController
{
    public function __construct(
        private readonly ChannelAdapterRegistry $adapterRegistry,
    ) {}

    /**
     * Handle an inbound webhook request.
     *
     * @param  Request  $request  Raw inbound HTTP request from the channel platform
     * @param  string  $channel  Channel identifier from URL (e.g., 'email', 'whatsapp')
     * @param  int|null  $accountId  Optional channel account ID from URL
     */
    public function __invoke(Request $request, string $channel, ?int $accountId = null): JsonResponse
    {
        $adapter = $this->adapterRegistry->resolve($channel);

        if ($adapter === null) {
            return response()->json([
                'status' => 'rejected',
                'reason' => 'unknown_channel',
            ], 401);
        }

        if ($adapter->verifyAuthenticity($request) !== SignalAuthenticityStatus::Verified) {
            return response()->json([
                'status' => 'rejected',
                'reason' => 'authenticity_failed',
            ], 401);
        }

        dispatch(ProcessInboundSignalJob::fromRequest($channel, $request, $accountId));

        return response()->json([
            'status' => 'accepted',
            'channel' => $channel,
        ], 202);
    }
}
