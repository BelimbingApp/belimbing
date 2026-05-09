<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Base\Integration\Services;

/**
 * Shared tracing / ownership metadata for OAuth2 token exchange requests.
 *
 * @phpstan-type Metadata array<string, mixed>
 */
final readonly class OAuth2TokenRequestContext
{
    /**
     * @param  Metadata  $metadata
     */
    public function __construct(
        public string $system = 'oauth2',
        public ?string $provider = null,
        public ?string $ownerType = null,
        public ?int $ownerId = null,
        public array $metadata = [],
    ) {}
}
