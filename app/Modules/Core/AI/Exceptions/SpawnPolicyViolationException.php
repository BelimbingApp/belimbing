<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\AI\Exceptions;

/**
 * Thrown when a spawn request violates orchestration policy.
 *
 * Examples: self-spawn attempt, delegation not allowed between agents.
 */
final class SpawnPolicyViolationException extends \RuntimeException
{
    public function __construct(
        public readonly int $parentEmployeeId,
        public readonly int $childEmployeeId,
    ) {
        parent::__construct(
            "Spawn policy violation: agent {$parentEmployeeId} cannot spawn child session for agent {$childEmployeeId}.",
        );
    }
}
