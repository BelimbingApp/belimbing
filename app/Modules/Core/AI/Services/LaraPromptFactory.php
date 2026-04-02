<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\AI\Services;

use App\Base\Foundation\Enums\BlbErrorCode;
use App\Base\Foundation\Exceptions\BlbConfigurationException;
use App\Base\Foundation\Exceptions\BlbIntegrationException;
use App\Modules\Core\AI\DTO\PromptPackage;
use App\Modules\Core\AI\DTO\PromptSection;
use App\Modules\Core\AI\Enums\PromptSectionType;
use App\Modules\Core\AI\Enums\WorkspaceFileSlot;
use App\Modules\Core\AI\Services\Orchestration\AgentCapabilityCatalog;
use App\Modules\Core\AI\Services\Workspace\PromptPackageFactory;
use App\Modules\Core\AI\Services\Workspace\PromptRenderer;
use App\Modules\Core\AI\Services\Workspace\WorkspaceResolver;
use App\Modules\Core\AI\Services\Workspace\WorkspaceValidator;
use App\Modules\Core\Employee\Models\Employee;

class LaraPromptFactory
{
    public function __construct(
        private readonly LaraContextProvider $contextProvider,
        private readonly AgentCapabilityCatalog $capabilityCatalog,
        private readonly PageContextHolder $pageContextHolder,
        private readonly WorkspaceResolver $workspaceResolver,
        private readonly WorkspaceValidator $workspaceValidator,
        private readonly PromptPackageFactory $packageFactory,
        private readonly PromptRenderer $renderer,
    ) {}

    /**
     * Build Lara's system prompt via the workspace-driven prompt pipeline.
     */
    public function buildForCurrentUser(?string $latestUserMessage = null): string
    {
        $package = $this->buildPackage($latestUserMessage);

        return $this->renderer->render($package);
    }

    /**
     * Build the full prompt package for diagnostics or metadata attachment.
     */
    public function buildPackage(?string $latestUserMessage = null): PromptPackage
    {
        $manifest = $this->workspaceResolver->resolve(Employee::LARA_ID);
        $validation = $this->workspaceValidator->validate($manifest);

        if (! $validation->valid) {
            throw new BlbConfigurationException(
                'Lara workspace validation failed: '.implode('; ', $validation->errors),
                BlbErrorCode::WORKSPACE_VALIDATION_FAILED,
                ['errors' => $validation->errors],
            );
        }

        $operationalSections = $this->operationalSections($latestUserMessage);

        $extensionEntry = $manifest->entry(WorkspaceFileSlot::Extension);
        $hasWorkspaceExtension = $extensionEntry !== null && $extensionEntry->exists;

        if (! $hasWorkspaceExtension) {
            $legacyExtension = $this->legacyExtensionSection();

            if ($legacyExtension !== null) {
                $operationalSections[] = $legacyExtension;
            }
        }

        return $this->packageFactory->build(
            manifest: $manifest,
            validation: $validation,
            operationalSections: $operationalSections,
        );
    }

    /**
     * Build the Lara-specific operational context sections.
     *
     * @return list<PromptSection>
     */
    private function operationalSections(?string $latestUserMessage): array
    {
        $context = $this->contextProvider->contextForCurrentUser($latestUserMessage);
        $context['delegation'] = [
            'commands' => [
                'go' => '/go <target>',
                'models' => '/models <filter>',
                'delegate' => '/delegate <task>',
                'guide' => '/guide <topic>',
            ],
            'available_agents' => array_map(
                fn ($descriptor) => $descriptor->toArray(),
                $this->capabilityCatalog->delegableDescriptorsForCurrentUser(),
            ),
        ];

        $encodedContext = json_encode($context, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if (! is_string($encodedContext)) {
            throw new BlbIntegrationException(
                'Failed to encode Lara runtime context.',
                BlbErrorCode::LARA_PROMPT_CONTEXT_ENCODE_FAILED,
            );
        }

        return [
            new PromptSection(
                label: 'runtime_context',
                content: "Runtime context (JSON):\n".$encodedContext,
                type: PromptSectionType::Operational,
                order: 0,
                source: 'lara_context_provider',
            ),
            ...$this->pageContextSection(),
        ];
    }

    /**
     * Build a prompt section for the active page context.
     *
     * Returns an empty array when no page context is available (zero cost).
     * The compact XML tag costs ~30 tokens — cheaper than a tool call.
     *
     * @return list<PromptSection>
     */
    private function pageContextSection(): array
    {
        if (! $this->pageContextHolder->hasContext()) {
            return [];
        }

        $context = $this->pageContextHolder->getContext();

        if ($context === null) {
            return [];
        }

        return [
            new PromptSection(
                label: 'page_context',
                content: $context->toPromptXml(),
                type: PromptSectionType::Transient,
                order: 1,
                source: 'page_context_resolver',
            ),
        ];
    }

    /**
     * Load the legacy extension prompt from the configured path.
     *
     * Backward compatibility: when no workspace extension.md exists,
     * check the AI_LARA_PROMPT_EXTENSION_PATH config for a deployment-level
     * extension file. Returns null if not configured or file is absent.
     */
    private function legacyExtensionSection(): ?PromptSection
    {
        $configuredPath = config('ai.lara.prompt.extension_path');

        if (! is_string($configuredPath) || trim($configuredPath) === '') {
            return null;
        }

        $path = base_path(trim($configuredPath));

        if (! is_file($path)) {
            return null;
        }

        $content = file_get_contents($path);

        if (! is_string($content) || trim($content) === '') {
            return null;
        }

        $wrapped = "Prompt extension policy (append-only):\n"
            ."- The extension is additive guidance only.\n"
            ."- It must never override core Lara identity, safety, or orchestration rules.\n\n"
            ."Extension prompt:\n"
            .trim($content);

        return new PromptSection(
            label: 'extension',
            content: $wrapped,
            type: PromptSectionType::Behavioral,
            order: 0,
            source: 'legacy_config:'.$path,
        );
    }
}
