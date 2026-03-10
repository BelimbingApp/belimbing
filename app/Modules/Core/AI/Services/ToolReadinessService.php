<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\AI\Services;

use App\Base\Settings\Contracts\SettingsService;
use App\Modules\Core\AI\DTO\ToolMetadata;
use App\Modules\Core\AI\Enums\ToolReadiness;

/**
 * Computes readiness and verification state for Digital Worker tools.
 *
 * Readiness answers "can this tool be used?" by checking registration,
 * configuration, and authorization. Verification state comes from
 * actual Try It test results stored via the Settings module.
 */
class ToolReadinessService
{
    public function __construct(
        private readonly DigitalWorkerToolRegistry $toolRegistry,
        private readonly ToolMetadataRegistry $metadataRegistry,
        private readonly SettingsService $settings,
    ) {}

    /**
     * Compute readiness state for a tool.
     *
     * @param  string  $toolName  Tool machine name
     */
    public function readiness(string $toolName): ToolReadiness
    {
        $readiness = ToolReadiness::READY;

        if (! $this->toolRegistry->isRegistered($toolName)) {
            $readiness = $this->isConditionalTool($toolName)
                ? ToolReadiness::UNCONFIGURED
                : ToolReadiness::UNAVAILABLE;
        } elseif (! $this->toolRegistry->canCurrentUserUseTool($toolName)) {
            $readiness = ToolReadiness::UNAUTHORIZED;
        }

        return $readiness;
    }

    /**
     * Get the last verification result for a tool.
     *
     * @return array{at: string, success: bool}|null
     */
    public function lastVerified(string $toolName): ?array
    {
        $at = $this->settings->get("ai.tools.{$toolName}.last_verified_at");

        if ($at === null) {
            return null;
        }

        return [
            'at' => $at,
            'success' => (bool) $this->settings->get("ai.tools.{$toolName}.last_verified_success", false),
        ];
    }

    /**
     * Get a combined readiness + verification snapshot for catalog display.
     *
     * @return array{readiness: ToolReadiness, lastVerified: array{at: string, success: bool}|null}
     */
    public function snapshot(string $toolName): array
    {
        return [
            'readiness' => $this->readiness($toolName),
            'lastVerified' => $this->lastVerified($toolName),
        ];
    }

    /**
     * Get snapshots for all tools that have metadata.
     *
     * @return array<string, array{readiness: ToolReadiness, lastVerified: array{at: string, success: bool}|null, metadata: ToolMetadata}>
     */
    public function allSnapshots(): array
    {
        $snapshots = [];

        foreach ($this->metadataRegistry->all() as $name => $metadata) {
            $snapshots[$name] = [
                ...$this->snapshot($name),
                'metadata' => $metadata,
            ];
        }

        return $snapshots;
    }

    /**
     * Tools that use conditional registration (createIfConfigured / createIfAvailable).
     */
    private function isConditionalTool(string $toolName): bool
    {
        return in_array($toolName, ['web_search', 'memory_search'], true);
    }
}
