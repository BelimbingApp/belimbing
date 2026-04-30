<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\AI\Values;

/**
 * Token-usage snapshot for a single LLM call.
 *
 * Maps a provider-emitted `usage` object (OpenAI Chat Completions, OpenAI
 * Responses / Codex Responses, Anthropic Messages) into a single normalized
 * shape the rest of the system consumes. Nullable fields are honest about
 * what the provider did not report — they are never zero-filled by default
 * because that would silently corrupt averages and cache-ratio math.
 */
final readonly class CallUsage
{
    /**
     * @param  int|null  $promptTokens  Total prompt tokens including the cached split
     * @param  int|null  $cachedInputTokens  Subset of prompt tokens served from prompt cache
     * @param  int|null  $completionTokens  Tokens in the completion (output)
     * @param  int|null  $reasoningTokens  Subset of completion tokens spent on hidden reasoning
     * @param  int|null  $totalTokens  Provider-reported total (may differ from prompt+completion)
     * @param  array<string, mixed>|null  $raw  Original provider payload preserved verbatim for forensics
     */
    public function __construct(
        public ?int $promptTokens = null,
        public ?int $cachedInputTokens = null,
        public ?int $completionTokens = null,
        public ?int $reasoningTokens = null,
        public ?int $totalTokens = null,
        public ?array $raw = null,
    ) {}

    /**
     * Build a CallUsage from a provider-shaped associative array.
     *
     * Recognized keys:
     * - `prompt_tokens` / `input_tokens` — total prompt tokens
     * - `completion_tokens` / `output_tokens` — completion tokens
     * - `total_tokens` — provider-supplied grand total
     * - `prompt_tokens_details.cached_tokens` — OpenAI-style cache split
     * - `cache_read_input_tokens` — Anthropic-style cache split
     * - `completion_tokens_details.reasoning_tokens` — OpenAI-style reasoning split
     *
     * Returns null when the input is empty or no recognized fields are
     * present, so callers can distinguish "provider reported nothing" from
     * "provider reported zero".
     *
     * @param  array<string, mixed>|null  $usage
     */
    public static function fromProviderArray(?array $usage): ?self
    {
        if ($usage === null || $usage === []) {
            return null;
        }

        $prompt = self::pickInt($usage, ['prompt_tokens', 'input_tokens']);
        $completion = self::pickInt($usage, ['completion_tokens', 'output_tokens']);
        $total = self::pickInt($usage, ['total_tokens']);

        $cached = null;
        if (is_array($usage['prompt_tokens_details'] ?? null)) {
            $cached = self::pickInt($usage['prompt_tokens_details'], ['cached_tokens', 'cache_read_input_tokens']);
        }
        if ($cached === null && is_array($usage['input_tokens_details'] ?? null)) {
            $cached = self::pickInt($usage['input_tokens_details'], ['cached_tokens', 'cache_read_input_tokens']);
        }
        if ($cached === null) {
            $cached = self::pickInt($usage, ['cache_read_input_tokens']);
        }

        $reasoning = null;
        if (is_array($usage['completion_tokens_details'] ?? null)) {
            $reasoning = self::pickInt($usage['completion_tokens_details'], ['reasoning_tokens']);
        }
        if ($reasoning === null && is_array($usage['output_tokens_details'] ?? null)) {
            $reasoning = self::pickInt($usage['output_tokens_details'], ['reasoning_tokens']);
        }

        if ($prompt === null && $completion === null && $total === null && $cached === null && $reasoning === null) {
            return null;
        }

        return new self(
            promptTokens: $prompt,
            cachedInputTokens: $cached,
            completionTokens: $completion,
            reasoningTokens: $reasoning,
            totalTokens: $total ?? self::derivedTotal($prompt, $completion),
            raw: $usage,
        );
    }

    /**
     * @return array{
     *     prompt_tokens: int|null,
     *     cached_input_tokens: int|null,
     *     completion_tokens: int|null,
     *     reasoning_tokens: int|null,
     *     total_tokens: int|null
     * }
     */
    public function toArray(): array
    {
        return [
            'prompt_tokens' => $this->promptTokens,
            'cached_input_tokens' => $this->cachedInputTokens,
            'completion_tokens' => $this->completionTokens,
            'reasoning_tokens' => $this->reasoningTokens,
            'total_tokens' => $this->totalTokens,
        ];
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

    private static function derivedTotal(?int $prompt, ?int $completion): ?int
    {
        if ($prompt === null && $completion === null) {
            return null;
        }

        return ($prompt ?? 0) + ($completion ?? 0);
    }
}
