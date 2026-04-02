<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\AI\DTO\ControlPlane;

use App\Modules\Core\AI\Enums\ControlPlaneTarget;
use App\Modules\Core\AI\Enums\TelemetryEventType;

final readonly class TelemetryRecordRequest
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public TelemetryEventType $eventType,
        public array $payload = [],
        public ?string $runId = null,
        public ?string $sessionId = null,
        public ?string $dispatchId = null,
        public ?int $employeeId = null,
        public ?ControlPlaneTarget $targetType = null,
        public ?string $targetId = null,
    ) {}
}
