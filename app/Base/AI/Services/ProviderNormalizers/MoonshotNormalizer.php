<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Base\AI\Services\ProviderNormalizers;

/**
 * Moonshot API-specific normalizations.
 *
 * Moonshot has several JSON Schema incompatibilities with OpenAI:
 * - Requires anyOf instead of oneOf for union types
 * - [Future: add other Moonshot-specific variants here]
 *
 * This normalizer adapts standard OpenAI-style schemas to Moonshot's
 * requirements. All transformations are lossless and semantically
 * equivalent per JSON Schema spec.
 */
class MoonshotNormalizer
{
    /**
     * Normalize tool schemas for Moonshot API compatibility.
     *
     * @param  list<array<string, mixed>>|null  $tools
     * @return list<array<string, mixed>>|null
     */
    public function normalizeTools(?array $tools): ?array
    {
        if ($tools === null) {
            return null;
        }

        return array_map(function (array $tool): array {
            if (! isset($tool['function']['parameters'])) {
                return $tool;
            }

            $tool['function']['parameters'] = $this->convertOneOfToAnyOf(
                $tool['function']['parameters']
            );

            return $tool;
        }, $tools);
    }

    /**
     * Recursively convert oneOf to anyOf in a JSON Schema.
     *
     * Moonshot's API requires anyOf for union types.
     * This is semantically equivalent per JSON Schema spec.
     */
    private function convertOneOfToAnyOf(mixed $schema): mixed
    {
        if (! is_array($schema)) {
            return $schema;
        }

        // Convert oneOf to anyOf
        if (isset($schema['oneOf'])) {
            $schema['anyOf'] = $schema['oneOf'];
            unset($schema['oneOf']);
        }

        // Recursively process nested schemas
        foreach ($schema as $key => $value) {
            if (is_array($value)) {
                $schema[$key] = $this->convertOneOfToAnyOf($value);
            }
        }

        return $schema;
    }
}
