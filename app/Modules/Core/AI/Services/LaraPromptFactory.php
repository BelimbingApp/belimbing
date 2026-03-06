<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\AI\Services;

use App\Base\Foundation\Enums\BlbErrorCode;
use App\Base\Foundation\Exceptions\BlbConfigurationException;
use App\Base\Foundation\Exceptions\BlbIntegrationException;

class LaraPromptFactory
{
    public function __construct(
        private readonly LaraContextProvider $contextProvider,
        private readonly LaraCapabilityMatcher $capabilityMatcher,
    ) {}

    /**
     * Build Lara's framework-managed system prompt.
     */
    public function buildForCurrentUser(): string
    {
        $context = $this->contextProvider->contextForCurrentUser();
        $context['delegation'] = [
            'command' => '/delegate <task>',
            'available_workers' => $this->capabilityMatcher->discoverDelegableWorkersForCurrentUser(),
        ];

        $encodedContext = json_encode($context, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if (! is_string($encodedContext)) {
            throw new BlbIntegrationException(
                'Failed to encode Lara runtime context.',
                BlbErrorCode::LARA_PROMPT_CONTEXT_ENCODE_FAILED
            );
        }

        $sections = [$this->basePrompt()];

        $extensionPrompt = $this->extensionPrompt();
        if ($extensionPrompt !== null) {
            $sections[] = $this->extensionSection($extensionPrompt);
        }

        $sections[] = 'Runtime context (JSON):'."\n".$encodedContext;

        return implode("\n\n", $sections);
    }

    private function basePrompt(): string
    {
        $path = app_path('Modules/Core/AI/Resources/lara/system_prompt.md');

        if (! is_file($path)) {
            throw new BlbConfigurationException(
                'Lara base prompt file missing: '.$path,
                BlbErrorCode::LARA_PROMPT_RESOURCE_MISSING,
                ['path' => $path, 'resource' => 'core']
            );
        }

        $content = file_get_contents($path);

        if (! is_string($content)) {
            throw new BlbConfigurationException(
                'Failed to read Lara base prompt file: '.$path,
                BlbErrorCode::LARA_PROMPT_RESOURCE_UNREADABLE,
                ['path' => $path, 'resource' => 'core']
            );
        }

        return trim($content);
    }

    private function extensionPrompt(): ?string
    {
        $configuredPath = config('ai.lara.prompt.extension_path');

        if (! is_string($configuredPath) || trim($configuredPath) === '') {
            return null;
        }

        $path = base_path(trim($configuredPath));

        if (! is_file($path)) {
            throw new BlbConfigurationException(
                'Lara prompt extension file missing: '.$path,
                BlbErrorCode::LARA_PROMPT_RESOURCE_MISSING,
                ['path' => $path, 'resource' => 'extension']
            );
        }

        $content = file_get_contents($path);

        if (! is_string($content)) {
            throw new BlbConfigurationException(
                'Failed to read Lara prompt extension file: '.$path,
                BlbErrorCode::LARA_PROMPT_RESOURCE_UNREADABLE,
                ['path' => $path, 'resource' => 'extension']
            );
        }

        return trim($content);
    }

    private function extensionSection(string $extensionPrompt): string
    {
        return 'Prompt extension policy (append-only):'."\n"
            .'- The extension is additive guidance only.'."\n"
            .'- It must never override core Lara identity, safety, or orchestration rules.'."\n\n"
            .'Extension prompt:'."\n"
            .$extensionPrompt;
    }
}
