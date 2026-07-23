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

    public const string PDFTOTEXT_PATH_KEY = 'ai.tools.document_analysis.pdftotext_path';

    public const string LARA_PROMPT_EXTENSION_PATH_KEY = 'ai.lara.prompt_extension_path';

    public const string BASH_TOOL_ENABLED_KEY = 'ai.tools.bash.enabled';

    public const string WEB_FETCH_TIMEOUT_KEY = 'ai.tools.web_fetch.timeout_seconds';

    public const string WEB_FETCH_MAX_BYTES_KEY = 'ai.tools.web_fetch.max_response_bytes';

    public const string BROWSER_ENABLED_KEY = 'ai.tools.browser.enabled';

    public const string BROWSER_EXECUTABLE_PATH_KEY = 'ai.tools.browser.executable_path';

    public function __construct(
        private SettingsService $settings,
        private SettingDefinitionRegistry $definitions,
    ) {}

    public function maxToolRounds(): int
    {
        return max(1, (int) $this->settings->get(
            self::MAX_TOOL_ROUNDS_KEY,
        ));
    }

    public function maxToolRoundsDefinition(): SettingDefinition
    {
        return $this->definition(self::MAX_TOOL_ROUNDS_KEY);
    }

    public function definition(string $key): SettingDefinition
    {
        return $this->definitions->get($key);
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

    public function laraPromptExtensionPath(): ?string
    {
        $path = $this->settings->get(self::LARA_PROMPT_EXTENSION_PATH_KEY);

        return is_string($path) && trim($path) !== '' ? trim($path) : null;
    }

    public function bashToolEnabled(): bool
    {
        return (bool) $this->settings->get(self::BASH_TOOL_ENABLED_KEY);
    }

    public function webFetchTimeoutSeconds(): int
    {
        return (int) $this->settings->get(self::WEB_FETCH_TIMEOUT_KEY);
    }

    public function webFetchMaxResponseBytes(): int
    {
        return (int) $this->settings->get(self::WEB_FETCH_MAX_BYTES_KEY);
    }

    public function browserEnabled(): bool
    {
        return (bool) $this->settings->get(self::BROWSER_ENABLED_KEY);
    }

    public function browserExecutablePath(): ?string
    {
        $path = $this->settings->get(self::BROWSER_EXECUTABLE_PATH_KEY);

        return is_string($path) && trim($path) !== '' ? trim($path) : null;
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
