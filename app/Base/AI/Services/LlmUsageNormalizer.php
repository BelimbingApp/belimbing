<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Base\AI\Services;

/**
 * Normalizes provider token-usage payloads into BLB's protocol event shape.
 */
final class LlmUsageNormalizer
{
    /**
     * @param  array<string, mixed>|null  $usage
     * @return array{
     *     prompt_tokens: int|null,
     *     cached_input_tokens: int|null,
     *     completion_tokens: int|null,
     *     reasoning_tokens: int|null,
     *     total_tokens: int|null,
     *     raw: array<string, mixed>
     * }|null
     */
    public static function fromProviderArray(?array $usage): ?array
    {
        if ($usage === null || $usage === []) {
            return null;
        }

        $promptTokens = self::pickInt($usage, ['prompt_tokens', 'input_tokens']);
        $completionTokens = self::pickInt($usage, ['completion_tokens', 'output_tokens']);
        $totalTokens = self::pickInt($usage, ['total_tokens']);
        $cachedInputTokens = self::cachedInputTokens($usage);
        $reasoningTokens = self::reasoningTokens($usage);

        if (
            $promptTokens === null
            && $cachedInputTokens === null
            && $completionTokens === null
            && $reasoningTokens === null
            && $totalTokens === null
        ) {
            return null;
        }

        return [
            'prompt_tokens' => $promptTokens,
            'cached_input_tokens' => $cachedInputTokens,
            'completion_tokens' => $completionTokens,
            'reasoning_tokens' => $reasoningTokens,
            'total_tokens' => $totalTokens ?? self::derivedTotal($promptTokens, $completionTokens),
            'raw' => $usage,
        ];
    }

    /**
     * @param  array<string, mixed>  $usage
     */
    private static function cachedInputTokens(array $usage): ?int
    {
        if (is_array($usage['prompt_tokens_details'] ?? null)) {
            $cached = self::pickInt($usage['prompt_tokens_details'], ['cached_tokens', 'cache_read_input_tokens']);
            if ($cached !== null) {
                return $cached;
            }
        }

        if (is_array($usage['input_tokens_details'] ?? null)) {
            $cached = self::pickInt($usage['input_tokens_details'], ['cached_tokens', 'cache_read_input_tokens']);
            if ($cached !== null) {
                return $cached;
            }
        }

        return self::pickInt($usage, ['cache_read_input_tokens']);
    }

    /**
     * @param  array<string, mixed>  $usage
     */
    private static function reasoningTokens(array $usage): ?int
    {
        if (is_array($usage['completion_tokens_details'] ?? null)) {
            $reasoning = self::pickInt($usage['completion_tokens_details'], ['reasoning_tokens']);
            if ($reasoning !== null) {
                return $reasoning;
            }
        }

        if (is_array($usage['output_tokens_details'] ?? null)) {
            $reasoning = self::pickInt($usage['output_tokens_details'], ['reasoning_tokens']);
            if ($reasoning !== null) {
                return $reasoning;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $source
     * @param  list<string>  $keys
     */
    private static function pickInt(array $source, array $keys): ?int
    {
        foreach ($keys as $key) {
            $value = $source[$key] ?? null;

            if (is_int($value)) {
                return $value;
            }

            if (is_numeric($value)) {
                return (int) $value;
            }
        }

        return null;
    }

    private static function derivedTotal(?int $promptTokens, ?int $completionTokens): ?int
    {
        if ($promptTokens === null && $completionTokens === null) {
            return null;
        }

        return ($promptTokens ?? 0) + ($completionTokens ?? 0);
    }
}
