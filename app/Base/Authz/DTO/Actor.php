<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Base\Authz\DTO;

use App\Base\Authz\Enums\AuthorizationReasonCode;
use App\Base\Authz\Enums\PrincipalType;
use App\Base\Foundation\Contracts\CompanyScoped;
use Illuminate\Contracts\Auth\Authenticatable;

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

    /**
     * Create an actor from an authenticated user.
     *
     * @param  array<string, mixed>  $attributes
     */
    public static function forUser(
        Authenticatable $user,
        PrincipalType $type = PrincipalType::USER,
        ?int $actingForUserId = null,
        array $attributes = [],
    ): self {
        return new self(
            type: $type,
            id: (int) $user->getAuthIdentifier(),
            companyId: self::resolveUserCompanyId($user),
            actingForUserId: $actingForUserId,
            attributes: $attributes,
        );
    }

    public function isUser(): bool
    {
        return $this->type === PrincipalType::USER;
    }

    public function isAgent(): bool
    {
        return $this->type === PrincipalType::AGENT;
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

        if ($this->isAgent() && $this->actingForUserId === null) {
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

    private static function resolveUserCompanyId(Authenticatable $user): ?int
    {
        if ($user instanceof CompanyScoped) {
            return $user->getCompanyId();
        }

        if (! method_exists($user, 'getAttribute')) {
            return null;
        }

        $companyId = $user->getAttribute('company_id');

        return $companyId !== null ? (int) $companyId : null;
    }
}
