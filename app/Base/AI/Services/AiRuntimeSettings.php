<?php

namespace App\Base\AI\Services;

use App\Base\Settings\Contracts\SettingsService;

/**
 * Resolves operator-managed AI runtime settings over shipped config defaults.
 */
final readonly class AiRuntimeSettings
{
    public const string MAX_TOOL_ITERATIONS_KEY = 'ai.llm.agentic.max_tool_iterations';

    public const string PDFTOTEXT_PATH_KEY = 'ai.tools.document_analysis.pdftotext_path';

    public const int DEFAULT_MAX_TOOL_ITERATIONS = 100;

    public function __construct(
        private SettingsService $settings,
    ) {}

    public function maxToolIterations(): int
    {
        return max(0, (int) $this->settings->get(
            self::MAX_TOOL_ITERATIONS_KEY,
            self::DEFAULT_MAX_TOOL_ITERATIONS,
        ));
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
