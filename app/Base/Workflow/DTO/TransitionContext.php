<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Base\Workflow\DTO;

use App\Base\Authz\DTO\Actor;

final readonly class TransitionContext
{
    /**
     * @param  Actor  $actor  The actor triggering the transition
     * @param  string|null  $comment  Optional comment for the history record
     * @param  string|null  $commentTag  Comment category (ties to StatusConfig.comment_tags)
     * @param  array<int, array<string, mixed>>|null  $assignees  Users delegated to complete the work
     * @param  array<int, array<string, mixed>>|null  $attachments  Supporting documents
     * @param  array<string, mixed>|null  $metadata  Process-specific data snapshot
     */
    public function __construct(
        public Actor $actor,
        public ?string $comment = null,
        public ?string $commentTag = null,
        public ?array $assignees = null,
        public ?array $attachments = null,
        public ?array $metadata = null,
    ) {}
}
