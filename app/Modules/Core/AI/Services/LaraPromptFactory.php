<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\AI\Services;

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
            throw new \RuntimeException('Failed to encode Lara runtime context.');
        }

        return $this->basePrompt()."\n\nRuntime context (JSON):\n".$encodedContext;
    }

    private function basePrompt(): string
    {
        $path = app_path('Modules/Core/AI/Resources/lara/system_prompt.md');

        if (! is_file($path)) {
            throw new \RuntimeException("Lara base prompt file missing: {$path}");
        }

        $content = file_get_contents($path);

        if (! is_string($content)) {
            throw new \RuntimeException("Failed to read Lara base prompt file: {$path}");
        }

        return trim($content);
    }
}
