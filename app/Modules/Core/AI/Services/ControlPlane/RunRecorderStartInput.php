<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\AI\Services\ControlPlane;

/**
 * Parameters for {@see RunRecorder::start()}.
 */
final readonly class RunRecorderStartInput
{
    public function __construct(
        public string $runId,
        public int $employeeId,
        public string $source,
        public string $executionMode = 'interactive',
        public ?string $sessionId = null,
        public ?int $actingForUserId = null,
        public ?int $timeoutSeconds = null,
        public ?string $turnId = null,
    ) {}
}
