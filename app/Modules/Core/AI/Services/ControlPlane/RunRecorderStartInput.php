<?php
namespace App\Modules\Core\AI\Services\ControlPlane;

/**
 * Parameters for beginning execution on an existing AiRun envelope.
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
    ) {}
}
