<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\AI\Exceptions;

/**
 * Thrown when a spawn request exceeds the maximum depth limit.
 *
 * Prevents unbounded recursive spawning of child sessions.
 */
final class SpawnDepthExceededException extends \RuntimeException
{
    public function __construct(
        public readonly int $requestedDepth,
        public readonly int $maxDepth,
    ) {
        parent::__construct(
            "Spawn depth {$requestedDepth} exceeds maximum allowed depth of {$maxDepth}.",
        );
    }
}
