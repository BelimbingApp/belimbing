<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Base\System\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

class ReverbTestMessageOccurred implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    public const EVENT_NAME = 'system-reverb-test';

    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public int $userId,
        public array $payload,
    ) {}

    public function broadcastOn(): Channel
    {
        return new Channel('system.reverb-test.'.$this->userId);
    }

    public function broadcastAs(): string
    {
        return self::EVENT_NAME;
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return $this->payload;
    }
}
