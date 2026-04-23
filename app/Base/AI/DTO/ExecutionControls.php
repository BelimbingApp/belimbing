<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Base\AI\DTO;

use App\Base\AI\Enums\ReasoningEffort;
use App\Base\AI\Enums\ReasoningMode;
use App\Base\AI\Enums\ReasoningVisibility;
use App\Base\AI\Enums\ToolChoiceMode;

final class ExecutionControls
{
    public readonly ExecutionLimitControls $limits;

    public readonly SamplingControls $sampling;

    public readonly ReasoningControls $reasoning;

    public readonly ToolExecutionControls $tools;

    public function __construct(
        ?ExecutionLimitControls $limits = null,
        ?SamplingControls $sampling = null,
        ?ReasoningControls $reasoning = null,
        ?ToolExecutionControls $tools = null,
    ) {
        $this->limits = $limits ?? new ExecutionLimitControls(2048);
        $this->sampling = $sampling ?? new SamplingControls;
        $this->reasoning = $reasoning ?? new ReasoningControls;
        $this->tools = $tools ?? new ToolExecutionControls;
    }

    public static function defaults(// NOSONAR (parameter count): kept as named-argument-friendly builder API
        int $maxOutputTokens = 2048,
        ?float $temperature = 0.7,
        ?ToolChoiceMode $toolChoice = null,
        ReasoningMode $reasoningMode = ReasoningMode::Auto,
        ReasoningVisibility $reasoningVisibility = ReasoningVisibility::None,
        ?ReasoningEffort $reasoningEffort = null,
        ?int $reasoningBudget = null,
        bool $preserveReasoningContext = false,
        ?float $topP = null,
        ?int $candidateCount = null,
        ?float $presencePenalty = null,
        ?float $frequencyPenalty = null,
    ): self {
        return new self(
            limits: new ExecutionLimitControls($maxOutputTokens),
            sampling: new SamplingControls(
                temperature: $temperature,
                topP: $topP,
                candidateCount: $candidateCount,
                presencePenalty: $presencePenalty,
                frequencyPenalty: $frequencyPenalty,
            ),
            reasoning: new ReasoningControls(
                mode: $reasoningMode,
                visibility: $reasoningVisibility,
                effort: $reasoningEffort,
                budget: $reasoningBudget,
            ),
            tools: new ToolExecutionControls(
                choice: $toolChoice,
                preserveReasoningContext: $preserveReasoningContext,
            ),
        );
    }

    /**
     * @param  array<string, mixed>  $config
     */
    public static function fromConfig(array $config, self $defaults): self
    {
        $limits = is_array($config['limits'] ?? null) ? $config['limits'] : [];
        $sampling = is_array($config['sampling'] ?? null) ? $config['sampling'] : [];
        $reasoning = is_array($config['reasoning'] ?? null) ? $config['reasoning'] : [];
        $tools = is_array($config['tools'] ?? null) ? $config['tools'] : [];

        return new self(
            limits: new ExecutionLimitControls(
                maxOutputTokens: (int) ($limits['max_output_tokens'] ?? $defaults->limits->maxOutputTokens),
            ),
            sampling: new SamplingControls(
                temperature: self::floatOrNull($sampling['temperature'] ?? $defaults->sampling->temperature),
                topP: self::floatOrNull($sampling['top_p'] ?? $defaults->sampling->topP),
                candidateCount: self::intOrNull($sampling['candidate_count'] ?? $defaults->sampling->candidateCount),
                presencePenalty: self::floatOrNull($sampling['presence_penalty'] ?? $defaults->sampling->presencePenalty),
                frequencyPenalty: self::floatOrNull($sampling['frequency_penalty'] ?? $defaults->sampling->frequencyPenalty),
            ),
            reasoning: new ReasoningControls(
                mode: ReasoningMode::tryFrom((string) ($reasoning['mode'] ?? $defaults->reasoning->mode->value))
                    ?? $defaults->reasoning->mode,
                visibility: ReasoningVisibility::tryFrom((string) ($reasoning['visibility'] ?? $defaults->reasoning->visibility->value))
                    ?? $defaults->reasoning->visibility,
                effort: ReasoningEffort::tryFrom((string) ($reasoning['effort'] ?? $defaults->reasoning->effort?->value ?? ''))
                    ?? $defaults->reasoning->effort,
                budget: self::intOrNull($reasoning['budget'] ?? $defaults->reasoning->budget),
            ),
            tools: new ToolExecutionControls(
                choice: ToolChoiceMode::tryFrom((string) ($tools['choice'] ?? $defaults->tools->choice?->value ?? ''))
                    ?? $defaults->tools->choice,
                preserveReasoningContext: (bool) ($tools['preserve_reasoning_context'] ?? $defaults->tools->preserveReasoningContext),
            ),
        );
    }

    /**
     * @return array{
     *     limits: array{max_output_tokens: int},
     *     sampling: array{temperature: ?float, top_p: ?float, candidate_count: ?int, presence_penalty: ?float, frequency_penalty: ?float},
     *     reasoning: array{mode: string, visibility: string, effort: ?string, budget: ?int},
     *     tools: array{choice: ?string, preserve_reasoning_context: bool}
     * }
     */
    public function toArray(): array
    {
        return [
            'limits' => $this->limits->toArray(),
            'sampling' => $this->sampling->toArray(),
            'reasoning' => $this->reasoning->toArray(),
            'tools' => $this->tools->toArray(),
        ];
    }

    public function withToolChoice(?ToolChoiceMode $choice): self
    {
        return new self(
            limits: $this->limits,
            sampling: $this->sampling,
            reasoning: $this->reasoning,
            tools: new ToolExecutionControls(
                choice: $choice,
                preserveReasoningContext: $this->tools->preserveReasoningContext,
            ),
        );
    }

    public function withReasoningVisibility(ReasoningVisibility $visibility): self
    {
        return new self(
            limits: $this->limits,
            sampling: $this->sampling,
            reasoning: new ReasoningControls(
                mode: $this->reasoning->mode,
                visibility: $visibility,
                effort: $this->reasoning->effort,
                budget: $this->reasoning->budget,
            ),
            tools: $this->tools,
        );
    }

    public function withReasoningContextPreservation(bool $preserveReasoningContext): self
    {
        return new self(
            limits: $this->limits,
            sampling: $this->sampling,
            reasoning: $this->reasoning,
            tools: new ToolExecutionControls(
                choice: $this->tools->choice,
                preserveReasoningContext: $preserveReasoningContext,
            ),
        );
    }

    private static function floatOrNull(mixed $value): ?float
    {
        return $value === null ? null : (float) $value;
    }

    private static function intOrNull(mixed $value): ?int
    {
        return $value === null ? null : (int) $value;
    }
}
