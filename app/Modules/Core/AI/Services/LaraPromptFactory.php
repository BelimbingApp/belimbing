<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\AI\Services;

use App\Base\Foundation\Enums\BlbErrorCode;
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
    public function buildForCurrentUser(?string $latestUserMessage = null): string
    {
        $context = $this->contextProvider->contextForCurrentUser($latestUserMessage);
        $context['delegation'] = [
            'commands' => [
                'go' => '/go <target>',
                'models' => '/models <filter>',
                'delegate' => '/delegate <task>',
                'guide' => '/guide <topic>',
            ],
            'available_agents' => $this->capabilityMatcher->discoverDelegableAgentsForCurrentUser(),
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
        return app(PromptResourceLoader::class)
            ->load(app_path('Modules/Core/AI/Resources/lara/system_prompt.md'), 'Lara base prompt', 'core');
    }

    private function extensionPrompt(): ?string
    {
        $configuredPath = config('ai.lara.prompt.extension_path');

        if (! is_string($configuredPath) || trim($configuredPath) === '') {
            return null;
        }

        $path = base_path(trim($configuredPath));

        return app(PromptResourceLoader::class)
            ->load($path, 'Lara prompt extension', 'extension');
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
