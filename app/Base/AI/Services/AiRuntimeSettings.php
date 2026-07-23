<?php

namespace App\Base\AI\Services;

use App\Base\Settings\Contracts\SettingsService;
use App\Base\Settings\DTO\SettingDefinition;
use App\Base\Settings\Services\SettingDefinitionRegistry;

/**
 * Typed access to operator-managed AI runtime settings.
 */
final readonly class AiRuntimeSettings
{
    public const string MAX_TOOL_ROUNDS_KEY = 'ai.llm.agentic.max_tool_rounds';

    public const string LEGACY_MAX_TOOL_ITERATIONS_KEY = 'ai.llm.agentic.max_tool_iterations';

    public const string PDFTOTEXT_PATH_KEY = 'ai.tools.document_analysis.pdftotext_path';

    public function __construct(
        private SettingsService $settings,
        private SettingDefinitionRegistry $definitions,
    ) {}

    public function maxToolRounds(): int
    {
        $key = match (true) {
            $this->settings->has(self::MAX_TOOL_ROUNDS_KEY) => self::MAX_TOOL_ROUNDS_KEY,
            $this->settings->has(self::LEGACY_MAX_TOOL_ITERATIONS_KEY) => self::LEGACY_MAX_TOOL_ITERATIONS_KEY,
            default => self::MAX_TOOL_ROUNDS_KEY,
        };

        return max(1, (int) $this->settings->get(
            $key,
        ));
    }

    public function maxToolRoundsDefinition(): SettingDefinition
    {
        return $this->definitions->get(self::MAX_TOOL_ROUNDS_KEY);
    }

    public function defaultMaxToolRounds(): int
    {
        return (int) $this->maxToolRoundsDefinition()->default;
    }

    /**
     * @return list<string>
     */
    public function maxToolRoundsRules(): array
    {
        return $this->maxToolRoundsDefinition()->rules;
    }

    public function pdfToTextPath(): ?string
    {
        $path = $this->settings->get(self::PDFTOTEXT_PATH_KEY);

        if (! is_string($path) || trim($path) === '') {
            return null;
        }

        return trim($path);
    }

    /**
     * Prefer the operator-pinned binary while retaining portable PATH lookup.
     *
     * @return list<string>
     */
    public function pdfToTextCandidates(): array
    {
        $configured = $this->pdfToTextPath();

        return array_values(array_filter([
            $configured,
            'pdftotext',
            'pdftotext.exe',
        ], fn (?string $candidate): bool => $candidate !== null && $candidate !== ''));
    }
}
