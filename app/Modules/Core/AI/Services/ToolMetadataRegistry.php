<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\AI\Services;

use App\Base\AI\Contracts\Tool;
use App\Modules\Core\AI\DTO\ToolConfigField;
use App\Modules\Core\AI\DTO\ToolMetadata;

/**
 * Assembles rich UI metadata from self-describing Tool instances.
 *
 * Each tool declares its own display metadata (displayName, summary,
 * explanation, test examples, health checks, limits, etc.) via the Tool
 * contract. This registry reads those methods and overlays governance-only
 * data (configFields for the few tools that have admin-configurable settings).
 *
 * Keyed by the tool's machine name matching Tool::name().
 */
class ToolMetadataRegistry
{
    /** @var array<string, ToolMetadata> */
    private array $metadata = [];

    /**
     * Build the registry from tool instances.
     *
     * @param  array<string, Tool>  $tools  All tool instances to index, keyed by name
     */
    public function __construct(array $tools = [])
    {
        foreach ($tools as $tool) {
            $this->metadata[$tool->name()] = $this->assembleFromTool($tool);
        }
    }

    /**
     * Get metadata for a specific tool.
     *
     * @param  string  $name  Tool machine name
     */
    public function get(string $name): ?ToolMetadata
    {
        return $this->metadata[$name] ?? null;
    }

    /**
     * Get metadata for all known tools.
     *
     * @return array<string, ToolMetadata>
     */
    public function all(): array
    {
        return $this->metadata;
    }

    /**
     * Register (or replace) metadata for a tool.
     *
     * Useful for tests or third-party tool packages that want to add
     * metadata for tools not discovered at boot time.
     */
    public function register(ToolMetadata $metadata): void
    {
        $this->metadata[$metadata->name] = $metadata;
    }

    /**
     * Check whether metadata exists for a given tool name.
     */
    public function has(string $name): bool
    {
        return isset($this->metadata[$name]);
    }

    /**
     * Assemble a ToolMetadata DTO from a self-describing Tool instance.
     *
     * Reads identity, classification, and UI metadata from the tool's own
     * methods, then overlays governance-only configFields for the small
     * number of tools that have admin-configurable settings.
     */
    private function assembleFromTool(Tool $tool): ToolMetadata
    {
        return new ToolMetadata(
            name: $tool->name(),
            displayName: $tool->displayName(),
            summary: $tool->summary(),
            explanation: $tool->explanation(),
            category: $tool->category(),
            riskClass: $tool->riskClass(),
            capability: $tool->requiredCapability(),
            setupRequirements: $tool->setupRequirements(),
            testExamples: $tool->testExamples(),
            healthChecks: $tool->healthChecks(),
            limits: $tool->limits(),
            configFields: $this->configFieldsFor($tool->name()),
        );
    }

    /**
     * Governance-only configuration fields for tools with admin settings.
     *
     * These reference Settings keys and UI field types — concerns that belong
     * in the Core governance layer, not on the stateless Base tool contract.
     * Only 3 of 20 tools currently have configurable settings.
     *
     * @return list<ToolConfigField>
     */
    private function configFieldsFor(string $toolName): array
    {
        return match ($toolName) {
            'web_search' => [
                new ToolConfigField(
                    key: 'ai.tools.web_search.cache_ttl_minutes',
                    label: 'Cache TTL (minutes)',
                    type: 'text',
                    help: 'How long to cache search results.',
                ),
            ],
            'web_fetch' => [
                new ToolConfigField(
                    key: 'ai.tools.web_fetch.timeout_seconds',
                    label: 'Timeout (seconds)',
                    type: 'text',
                    help: 'Maximum time to wait for a response.',
                ),
                new ToolConfigField(
                    key: 'ai.tools.web_fetch.max_response_bytes',
                    label: 'Max Response Size (bytes)',
                    type: 'text',
                    help: 'Maximum response body size.',
                ),
            ],
            'browser' => [
                new ToolConfigField(
                    key: 'ai.tools.browser.enabled',
                    label: 'Enable Browser Tool',
                    type: 'boolean',
                    help: 'Whether browser automation is enabled.',
                ),
                new ToolConfigField(
                    key: 'ai.tools.browser.executable_path',
                    label: 'Chromium Path',
                    type: 'text',
                    help: 'Path to the Chromium executable. Leave empty for auto-detection.',
                ),
            ],
            default => [],
        };
    }
}
