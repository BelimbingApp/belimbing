<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Base\Authz\Contracts;

use App\Base\Authz\DTO\Actor;
use App\Base\Authz\DTO\AuthorizationDecision;
use App\Base\Authz\DTO\ResourceContext;

/**
 * A composable authorization policy stage.
 *
 * Policies are evaluated in pipeline order. Each policy can:
 * - Return null to abstain (continue to next policy)
 * - Return a deny decision to halt evaluation immediately
 * - Return an allow decision (typically only the final grant policy)
 */
interface AuthorizationPolicy
{
    /**
     * Unique key identifying this policy for audit trails.
     */
    public function key(): string;

    /**
     * Evaluate this policy stage.
     *
     * @param  Actor  $actor  The principal requesting authorization
     * @param  string  $capability  The capability key being checked
     * @param  ResourceContext|null  $resource  Optional resource context
     * @param  array<string, mixed>  $context  Additional context
     * @return AuthorizationDecision|null Decision to halt pipeline, or null to continue
     */
    public function evaluate(
        Actor $actor,
        string $capability,
        ?ResourceContext $resource,
        array $context
    ): ?AuthorizationDecision;
}
