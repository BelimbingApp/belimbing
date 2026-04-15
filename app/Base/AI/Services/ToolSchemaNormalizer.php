<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Base\AI\Services;

use App\Base\AI\Services\ProviderNormalizers\DefaultNormalizer;
use App\Base\AI\Services\ProviderNormalizers\MoonshotNormalizer;

/**
 * Dispatches tool schema normalization to provider-specific normalizers.
 *
 * This is a shallow factory that routes to the appropriate deep module
 * based on provider name. Each provider normalizer handles all API
 * variants for that specific provider.
 *
 * Provider normalizers:
 * - MoonshotNormalizer: Moonshot API quirks (anyOf vs oneOf, etc.)
 * - DefaultNormalizer: No transformations (OpenAI, Anthropic, etc.)
 *
 * Usage:
 *   $normalized = ToolSchemaNormalizer::forProvider('moonshotai')
 *       ->normalizeTools($tools);
 */
class ToolSchemaNormalizer
{
    private object $normalizer;

    private function __construct(object $normalizer)
    {
        $this->normalizer = $normalizer;
    }

    /**
     * Get the appropriate normalizer for a provider.
     *
     * @param  string|null  $providerName  Provider name (e.g., 'moonshotai', 'openai')
     */
    public static function forProvider(?string $providerName): self
    {
        $normalizer = match ($providerName) {
            'moonshotai' => new MoonshotNormalizer,
            default => new DefaultNormalizer,
        };

        return new self($normalizer);
    }

    /**
     * Normalize tool schemas for the target provider.
     *
     * @param  list<array<string, mixed>>|null  $tools
     * @return list<array<string, mixed>>|null
     */
    public function normalizeTools(?array $tools): ?array
    {
        return $this->normalizer->normalizeTools($tools);
    }
}
