<?php

namespace App\Base\AI\DTO;

/**
 * Family-neutral summary of one AI provider for the providers hub. Carries
 * only the thin shared spine — identity, connection state, and where to manage
 * it. Each family's thick parts (capability model, runtime, billing, model
 * selection) stay behind the family and never leak into this DTO.
 */
final readonly class AiProviderSummary
{
    public function __construct(
        public string $familyKey,
        public string $providerKey,
        public string $displayName,
        // Usable for work now (credentials stored AND a client wired).
        public bool $connected,
        // Whether credentials are stored. Distinct from `connected`: a provider
        // can be configured but not yet wired to a client.
        public bool $configured = false,
        // One-line description of the provider and what it does.
        public string $description = '',
        // Whether this provider is the operator's active choice for the
        // family's work (e.g. the selected photo-cleanup adapter). Only
        // meaningful when `connected` is true; the operator surface uses this
        // to mark the active row without re-resolving the adapter.
        public bool $active = false,
    ) {}
}
