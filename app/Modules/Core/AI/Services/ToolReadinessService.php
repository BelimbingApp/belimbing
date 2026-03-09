<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\AI\Services;

use App\Modules\Core\AI\DTO\ToolMetadata;
use App\Modules\Core\AI\Enums\ToolHealthState;
use App\Modules\Core\AI\Enums\ToolReadiness;
use Illuminate\Support\Facades\DB;

/**
 * Computes readiness and health state for Digital Worker tools.
 *
 * Readiness answers "can this tool be used?" by checking registration,
 * configuration, and authorization. Health answers "is it behaving well
 * right now?" by running lightweight probes.
 */
class ToolReadinessService
{
    public function __construct(
        private readonly DigitalWorkerToolRegistry $toolRegistry,
        private readonly ToolMetadataRegistry $metadataRegistry,
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
     * Run a basic health check for a tool.
     *
     * @param  string  $toolName  Tool machine name
     */
    public function health(string $toolName): ToolHealthState
    {
        if (! $this->toolRegistry->isRegistered($toolName)) {
            return ToolHealthState::UNKNOWN;
        }

        return match ($toolName) {
            'query_data' => $this->checkQueryDataHealth(),
            'web_search' => $this->checkWebSearchHealth(),
            'web_fetch' => $this->checkWebFetchHealth(),
            'system_info' => ToolHealthState::HEALTHY,
            default => ToolHealthState::UNKNOWN,
        };
    }

    /**
     * Get a combined readiness + health snapshot for catalog display.
     *
     * @return array{readiness: ToolReadiness, health: ToolHealthState}
     */
    public function snapshot(string $toolName): array
    {
        return [
            'readiness' => $this->readiness($toolName),
            'health' => $this->health($toolName),
        ];
    }

    /**
     * Get snapshots for all tools that have metadata.
     *
     * @return array<string, array{readiness: ToolReadiness, health: ToolHealthState, metadata: ToolMetadata}>
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

    private function checkQueryDataHealth(): ToolHealthState
    {
        try {
            DB::select('SELECT 1');

            return ToolHealthState::HEALTHY;
        } catch (\Throwable) {
            return ToolHealthState::FAILING;
        }
    }

    private function checkWebSearchHealth(): ToolHealthState
    {
        $provider = config('ai.tools.web_search.provider', 'parallel');
        $apiKey = config('ai.tools.web_search.'.$provider.'.api_key');

        if (! is_string($apiKey) || trim($apiKey) === '') {
            return ToolHealthState::FAILING;
        }

        return ToolHealthState::HEALTHY;
    }

    private function checkWebFetchHealth(): ToolHealthState
    {
        // SSRF guard is always available; just check basic HTTP capability
        return ToolHealthState::HEALTHY;
    }
}
