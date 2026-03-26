<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Base\Workflow\DTO;

use Illuminate\Support\Carbon;

/**
 * Flattened, stable payload for transition event listeners.
 *
 * Listeners and projectors receive this DTO instead of reconstructing
 * values from the model, transition, and context objects individually.
 */
final readonly class TransitionPayload
{
    /**
     * @param  string  $flow  The flow identifier (e.g., 'quality_ncr')
     * @param  string  $flowModel  The model class name
     * @param  int  $flowId  The model primary key
     * @param  string|null  $fromStatus  The previous status code
     * @param  string  $toStatus  The new status code
     * @param  int|null  $actorId  The user who triggered the transition
     * @param  string|null  $actorRole  The actor's role at transition time
     * @param  string|null  $actorDepartment  The actor's department at transition time
     * @param  array<int, array<string, mixed>>|null  $assignees  Users delegated to complete the work
     * @param  string|null  $comment  Transition comment
     * @param  string|null  $commentTag  Comment category
     * @param  array<int, array<string, mixed>>|null  $attachments  Supporting documents
     * @param  array<string, mixed>|null  $metadata  Process-specific data snapshot
     * @param  Carbon  $transitionedAt  When the transition occurred
     */
    public function __construct(
        public string $flow,
        public string $flowModel,
        public int $flowId,
        public ?string $fromStatus,
        public string $toStatus,
        public ?int $actorId,
        public ?string $actorRole,
        public ?string $actorDepartment,
        public ?array $assignees,
        public ?string $comment,
        public ?string $commentTag,
        public ?array $attachments,
        public ?array $metadata,
        public Carbon $transitionedAt,
    ) {}
}
