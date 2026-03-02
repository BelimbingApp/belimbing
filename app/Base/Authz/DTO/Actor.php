<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Base\Authz\DTO;

use App\Base\Authz\Enums\AuthorizationReasonCode;
use App\Base\Authz\Enums\PrincipalType;

final readonly class Actor
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function __construct(
        public PrincipalType $type,
        public int $id,
        public ?int $companyId,
        public ?int $actingForUserId = null,
        public array $attributes = [],
    ) {}

    public function isHumanUser(): bool
    {
        return $this->type === PrincipalType::HUMAN_USER;
    }

    public function isDigitalWorker(): bool
    {
        return $this->type === PrincipalType::DIGITAL_WORKER;
    }

    /**
     * Validate minimum actor context for authorization.
     *
     * Returns null when valid, or a denial decision when invalid.
     */
    public function validate(): ?AuthorizationDecision
    {
        if ($this->id <= 0 || $this->companyId === null) {
            return AuthorizationDecision::deny(
                AuthorizationReasonCode::DENIED_INVALID_ACTOR_CONTEXT,
                ['actor_validation']
            );
        }

        if ($this->isDigitalWorker() && $this->actingForUserId === null) {
            return AuthorizationDecision::deny(
                AuthorizationReasonCode::DENIED_INVALID_ACTOR_CONTEXT,
                ['actor_validation']
            );
        }

        return null;
    }

    /**
     * Cache key representing this actor's identity for permission lookups.
     */
    public function cacheKey(): string
    {
        return $this->type->value.':'.$this->id.':'.$this->companyId;
    }
}
