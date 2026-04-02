<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\AI\DTO\ControlPlane;

use App\Modules\Core\AI\Enums\LifecycleAction;
use App\Modules\Core\AI\Enums\LifecycleActionStatus;

/**
 * Record of a lifecycle control request — from preview through execution.
 *
 * Every lifecycle action (compaction, prune, sweep) is tracked as an
 * explicit request with scope, policy, preview, status, and outcome
 * so operators can audit what happened and why.
 */
final readonly class LifecycleRequest
{
    /**
     * @param  string  $requestId  Unique request identifier
     * @param  LifecycleAction  $action  The lifecycle action type
     * @param  array<string, mixed>  $scope  Scope parameters (agent ID, thresholds, etc.)
     * @param  LifecycleActionStatus  $status  Current status of the request
     * @param  LifecyclePreview|null  $preview  Preview generated before execution (null if not yet previewed)
     * @param  array<string, mixed>|null  $result  Outcome summary after execution
     * @param  string|null  $errorMessage  Error description if the action failed
     * @param  int|null  $requestedBy  User ID who initiated the request (null for system)
     * @param  string  $createdAt  ISO 8601 timestamp when request was created
     * @param  string|null  $executedAt  ISO 8601 timestamp when execution completed
     */
    public function __construct(
        public string $requestId,
        public LifecycleAction $action,
        public array $scope,
        public LifecycleActionStatus $status,
        public ?LifecyclePreview $preview,
        public ?array $result,
        public ?string $errorMessage,
        public ?int $requestedBy,
        public string $createdAt,
        public ?string $executedAt,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'request_id' => $this->requestId,
            'action' => $this->action->value,
            'scope' => $this->scope,
            'status' => $this->status->value,
            'preview' => $this->preview?->toArray(),
            'result' => $this->result,
            'error_message' => $this->errorMessage,
            'requested_by' => $this->requestedBy,
            'created_at' => $this->createdAt,
            'executed_at' => $this->executedAt,
        ];
    }
}
