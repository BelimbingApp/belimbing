<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\AI\Jobs;

use App\Modules\Core\AI\Services\Messaging\InboundRoutingService;
use App\Modules\Core\AI\Services\Messaging\InboundSignalService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Http\Request;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Queue job that processes an inbound webhook signal.
 *
 * Accepts serialized request data (since Request objects are not
 * directly serializable), ingests through InboundSignalService for
 * normalization and persistence, then routes through InboundRoutingService.
 *
 * This job is dispatched by the webhook controller to move heavy
 * processing off the request path.
 */
class ProcessInboundSignalJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * Dedicated queue for inbound signal processing.
     */
    public const QUEUE = 'ai-inbound-signals';

    /**
     * The number of seconds the job can run before timing out.
     */
    public int $timeout = 60;

    /**
     * @param  string  $channel  Channel identifier (e.g., 'email', 'whatsapp')
     * @param  array<string, mixed>  $requestData  Serialized request body
     * @param  array<string, mixed>  $requestHeaders  Serialized request headers
     * @param  string  $requestMethod  HTTP method
     * @param  string  $requestUrl  Full request URL
     * @param  int|null  $channelAccountId  Optional specific account ID
     */
    public function __construct(
        public readonly string $channel,
        public readonly array $requestData,
        public readonly array $requestHeaders,
        public readonly string $requestMethod,
        public readonly string $requestUrl,
        public readonly ?int $channelAccountId = null,
    ) {
        $this->onQueue(self::QUEUE);
    }

    /**
     * Create a job from an HTTP request.
     *
     * Serializes the request into queue-safe data before dispatch.
     *
     * @param  string  $channel  Channel identifier
     * @param  Request  $request  The inbound HTTP request
     * @param  int|null  $channelAccountId  Optional account ID
     */
    public static function fromRequest(string $channel, Request $request, ?int $channelAccountId = null): self
    {
        return new self(
            channel: $channel,
            requestData: $request->all(),
            requestHeaders: $request->headers->all(),
            requestMethod: $request->method(),
            requestUrl: $request->fullUrl(),
            channelAccountId: $channelAccountId,
        );
    }

    /**
     * Process the inbound signal.
     */
    public function handle(InboundSignalService $signalService, InboundRoutingService $routingService): void
    {
        $request = $this->reconstructRequest();

        $signal = $signalService->ingest($this->channel, $request, $this->channelAccountId);

        if ($signal === null) {
            Log::info('Inbound signal produced no record.', [
                'channel' => $this->channel,
                'account_id' => $this->channelAccountId,
            ]);

            return;
        }

        $outcome = $routingService->route($signal);

        Log::info('Inbound signal routed.', [
            'signal_id' => $signal->id,
            'channel' => $this->channel,
            'disposition' => $outcome->disposition,
            'conversation_id' => $outcome->conversationId,
        ]);
    }

    /**
     * Reconstruct an HTTP Request from the serialized data.
     */
    private function reconstructRequest(): Request
    {
        $request = Request::create(
            uri: $this->requestUrl,
            method: $this->requestMethod,
            parameters: $this->requestData,
        );

        $request->headers->replace($this->requestHeaders);

        return $request;
    }
}
