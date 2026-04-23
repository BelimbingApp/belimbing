<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Base\AI\Services;

use App\Base\AI\DTO\ExecutionControls;
use App\Base\AI\DTO\ProviderExecutionCapabilities;
use App\Base\AI\DTO\SamplingControls;
use App\Base\AI\Enums\AiApiType;
use App\Base\AI\Enums\ReasoningEffort;
use App\Base\AI\Enums\ReasoningMode;
use App\Base\AI\Enums\ReasoningVisibility;
use App\Base\AI\Enums\ToolChoiceMode;
use App\Base\AI\Services\ProviderMapping\ProviderCapabilityRegistry;

final class ExecutionControlSchemaFactory
{
    private const SYSTEM_DEFAULT = 'System default';

    public function __construct(
        private readonly ProviderCapabilityRegistry $capabilities,
    ) {}

    /**
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    public function normalize(array $config): array
    {
        return ExecutionControls::fromConfig($config, $this->defaultControls())->toArray();
    }

    public function defaultControls(): ExecutionControls
    {
        return ExecutionControls::fromConfig(
            config('ai.llm.execution_controls', []),
            ExecutionControls::defaults(),
        );
    }

    /**
     * @return array{
     *     provider_name: ?string,
     *     model: string,
     *     api_type: string,
     *     groups: list<array{
     *         key: string,
     *         label: string,
     *         controls: list<array<string, mixed>>
     *     }>,
     *     notes: list<string>
     * }
     */
    public function build(
        ?string $providerName,
        string $model,
        AiApiType $apiType,
        ExecutionControls $controls,
    ): array {
        $defaults = $this->defaultControls();
        $capabilities = $this->capabilities->capabilitiesFor($providerName, $model, $apiType);
        $fixedSampling = $this->fixedSamplingFor($controls, $capabilities);
        $notes = [];

        $groups = [
            [
                'key' => 'limits',
                'label' => 'Limits',
                'controls' => [
                    $this->numberControl(
                        path: 'limits.max_output_tokens',
                        label: 'Max output tokens',
                        description: 'Upper bound for model output length.',
                        options: [
                            'current_value' => $controls->limits->maxOutputTokens,
                            'default_value' => $defaults->limits->maxOutputTokens,
                            'min' => 1,
                            'step' => 1,
                        ],
                    ),
                ],
            ],
            [
                'key' => 'sampling',
                'label' => 'Sampling',
                'controls' => [
                    $this->numberControl(
                        path: 'sampling.temperature',
                        label: 'Temperature',
                        description: $fixedSampling === null
                            ? 'Controls output randomness for this model.'
                            : 'The provider enforces the applied value shown here for the current reasoning mode.',
                        options: [
                            'current_value' => $controls->sampling->temperature,
                            'default_value' => $defaults->sampling->temperature,
                            'min' => 0,
                            'max' => 2,
                            'step' => 0.1,
                            'editable' => $fixedSampling === null,
                            'display_value' => $fixedSampling?->temperature,
                            'read_only_reason' => $fixedSampling === null ? null : 'Provider-enforced value',
                        ],
                    ),
                ],
            ],
            [
                'key' => 'reasoning',
                'label' => 'Reasoning',
                'controls' => $this->reasoningControls($controls, $defaults, $capabilities),
            ],
            [
                'key' => 'tools',
                'label' => 'Tool Loop',
                'controls' => $this->toolControls($controls, $defaults, $capabilities),
            ],
        ];

        if ($fixedSampling !== null) {
            $notes[] = sprintf(
                'This model family also enforces top-p %s, candidate count %s, presence penalty %s, and frequency penalty %s in the current reasoning mode.',
                $this->formatNumber($fixedSampling->topP),
                $this->formatValue($fixedSampling->candidateCount),
                $this->formatNumber($fixedSampling->presencePenalty),
                $this->formatNumber($fixedSampling->frequencyPenalty),
            );
        }

        return [
            'provider_name' => $providerName,
            'model' => $model,
            'api_type' => $apiType->value,
            'groups' => array_values(array_filter(
                $groups,
                fn (array $group): bool => $group['controls'] !== []
            )),
            'notes' => $notes,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function reasoningControls(
        ExecutionControls $controls,
        ExecutionControls $defaults,
        ProviderExecutionCapabilities $capabilities,
    ): array {
        if (! $this->hasReasoningControls($capabilities)) {
            return [];
        }

        $controlsList = [
            $this->selectControl(
                path: 'reasoning.mode',
                label: 'Reasoning mode',
                description: 'Chooses whether the provider should use advanced reasoning behavior when available.',
                currentValue: $controls->reasoning->mode->value,
                defaultValue: $defaults->reasoning->mode->value,
                options: array_map(
                    fn (ReasoningMode $mode): array => ['value' => $mode->value, 'label' => $this->reasoningModeLabel($mode)],
                    ReasoningMode::cases(),
                ),
            ),
        ];

        if ($capabilities->supportedReasoningVisibility !== []) {
            $controlsList[] = $this->selectControl(
                path: 'reasoning.visibility',
                label: 'Reasoning visibility',
                description: 'Controls how much reasoning detail Belimbing asks the provider to expose.',
                currentValue: $controls->reasoning->visibility->value,
                defaultValue: $defaults->reasoning->visibility->value,
                options: array_map(
                    fn (ReasoningVisibility $visibility): array => ['value' => $visibility->value, 'label' => $this->reasoningVisibilityLabel($visibility)],
                    $capabilities->supportedReasoningVisibility,
                ),
            );
        }

        if ($capabilities->supportedReasoningEffort !== []) {
            $controlsList[] = $this->selectControl(
                path: 'reasoning.effort',
                label: 'Reasoning effort',
                description: 'Hints how much reasoning work the provider should spend before answering.',
                currentValue: $controls->reasoning->effort?->value,
                defaultValue: $defaults->reasoning->effort?->value,
                options: array_merge(
                    [['value' => '', 'label' => self::SYSTEM_DEFAULT]],
                    array_map(
                        fn (ReasoningEffort $effort): array => ['value' => $effort->value, 'label' => ucfirst($effort->value)],
                        $capabilities->supportedReasoningEffort,
                    ),
                ),
            );
        }

        if ($capabilities->supportsReasoningBudget) {
            $controlsList[] = $this->numberControl(
                path: 'reasoning.budget',
                label: 'Reasoning budget',
                description: 'Optional token budget for provider-side reasoning work.',
                options: [
                    'current_value' => $controls->reasoning->budget,
                    'default_value' => $capabilities->defaultReasoningBudget,
                    'min' => 1,
                    'step' => 1,
                ],
            );
        }

        return $controlsList;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function toolControls(
        ExecutionControls $controls,
        ExecutionControls $defaults,
        ProviderExecutionCapabilities $capabilities,
    ): array {
        if (! $capabilities->supportsReasoningContextPreservation) {
            return [];
        }

        return [
            $this->checkboxControl(
                path: 'tools.preserve_reasoning_context',
                label: 'Preserve reasoning context across tool turns',
                description: 'Keeps provider-native reasoning state when a response continues after tool execution.',
                currentValue: $controls->tools->preserveReasoningContext,
                defaultValue: $defaults->tools->preserveReasoningContext,
            ),
        ];
    }

    private function hasReasoningControls(ProviderExecutionCapabilities $capabilities): bool
    {
        return $capabilities->supportedReasoningVisibility !== []
            || $capabilities->supportedReasoningEffort !== []
            || $capabilities->supportsReasoningBudget
            || $capabilities->fixedSamplingWhenReasoningEnabled !== null
            || $capabilities->fixedSamplingWhenReasoningDisabled !== null
            || $capabilities->supportsNativeReasoningBlocks
            || $capabilities->supportsAdaptiveReasoning;
    }

    private function fixedSamplingFor(
        ExecutionControls $controls,
        ProviderExecutionCapabilities $capabilities,
    ): ?SamplingControls {
        return $controls->reasoning->mode === ReasoningMode::Disabled
            ? $capabilities->fixedSamplingWhenReasoningDisabled
            : $capabilities->fixedSamplingWhenReasoningEnabled;
    }

    /**
     * @param  array{
     *     current_value?: int|float|null,
     *     default_value?: int|float|null,
     *     min?: ?int,
     *     max?: ?int,
     *     step?: int|float|null,
     *     editable?: bool,
     *     display_value?: int|float|null,
     *     read_only_reason?: ?string
     * }  $options
     * @return array<string, mixed>
     */
    private function numberControl(
        string $path,
        string $label,
        string $description,
        array $options,
    ): array {
        $currentValue = $options['current_value'] ?? null;
        $displayValue = $options['display_value'] ?? $currentValue;

        return [
            'path' => $path,
            'type' => 'number',
            'label' => $label,
            'description' => $description,
            'editable' => $options['editable'] ?? true,
            'current_value' => $currentValue,
            'default_value' => $options['default_value'] ?? null,
            'display_value' => $displayValue,
            'display_text' => $this->formatValue($displayValue),
            'read_only_reason' => $options['read_only_reason'] ?? null,
            'min' => $options['min'] ?? null,
            'max' => $options['max'] ?? null,
            'step' => $options['step'] ?? null,
        ];
    }

    /**
     * @param  list<array{value: string, label: string}>  $options
     * @return array<string, mixed>
     */
    private function selectControl(
        string $path,
        string $label,
        string $description,
        ?string $currentValue,
        ?string $defaultValue,
        array $options,
    ): array {
        return [
            'path' => $path,
            'type' => 'select',
            'label' => $label,
            'description' => $description,
            'editable' => true,
            'current_value' => $currentValue,
            'default_value' => $defaultValue,
            'display_value' => $currentValue,
            'display_text' => $this->labelForOption($options, $currentValue),
            'options' => $options,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function checkboxControl(
        string $path,
        string $label,
        string $description,
        bool $currentValue,
        bool $defaultValue,
    ): array {
        return [
            'path' => $path,
            'type' => 'checkbox',
            'label' => $label,
            'description' => $description,
            'editable' => true,
            'current_value' => $currentValue,
            'default_value' => $defaultValue,
            'display_value' => $currentValue,
            'display_text' => $currentValue ? 'Enabled' : 'Disabled',
        ];
    }

    /**
     * @param  list<array{value: string, label: string}>  $options
     */
    private function labelForOption(array $options, ?string $value): string
    {
        if ($value === null || $value === '') {
            return self::SYSTEM_DEFAULT;
        }

        foreach ($options as $option) {
            if ($option['value'] === $value) {
                return $option['label'];
            }
        }

        return $value;
    }

    private function reasoningModeLabel(ReasoningMode $mode): string
    {
        return match ($mode) {
            ReasoningMode::Auto => 'Auto',
            ReasoningMode::Enabled => 'Enabled',
            ReasoningMode::Disabled => 'Disabled',
        };
    }

    private function reasoningVisibilityLabel(ReasoningVisibility $visibility): string
    {
        return match ($visibility) {
            ReasoningVisibility::None => 'None',
            ReasoningVisibility::Summary => 'Summary',
            ReasoningVisibility::Full => 'Full',
        };
    }

    private function formatValue(mixed $value): string
    {
        if ($value === null || $value === '') {
            return self::SYSTEM_DEFAULT;
        }

        return match (true) {
            is_bool($value) => $value ? 'Enabled' : 'Disabled',
            is_int($value) => (string) $value,
            is_float($value) => $this->formatNumber($value),
            $value instanceof ToolChoiceMode => ucfirst($value->value),
            default => (string) $value,
        };
    }

    private function formatNumber(?float $value): string
    {
        if ($value === null) {
            return self::SYSTEM_DEFAULT;
        }

        return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');
    }
}
