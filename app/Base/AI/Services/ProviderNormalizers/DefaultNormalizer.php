<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Base\AI\Services\ProviderNormalizers;

/**
 * Default tool schema normalizer - no transformations.
 *
 * Used for providers that accept standard OpenAI-style schemas
 * without modification (OpenAI, Anthropic, Google, etc.)
 */
class DefaultNormalizer
{
    /**
     * Pass through tools unchanged.
     *
     * @param  list<array<string, mixed>>|null  $tools
     * @return list<array<string, mixed>>|null
     */
    public function normalizeTools(?array $tools): ?array
    {
        return $tools;
    }
}
