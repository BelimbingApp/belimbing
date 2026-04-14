<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\AI\Services\Workspace;

use App\Modules\Core\AI\DTO\WorkspaceFileEntry;
use App\Modules\Core\AI\DTO\WorkspaceManifest;
use App\Modules\Core\AI\Enums\WorkspaceFileSlot;
use App\Modules\Core\Employee\Models\Employee;

/**
 * Resolves the effective workspace file set for an agent.
 *
 * For each canonical slot, checks the workspace directory first, then
 * falls back to framework-provided resources for system agents.
 * Never escapes the configured workspace root.
 */
class WorkspaceResolver
{
    /**
     * Framework resource paths for system agents, keyed by employee ID.
     *
     * @var array<int, string>
     */
    private const SYSTEM_AGENT_RESOURCES = [
        Employee::LARA_ID => 'Modules/Core/AI/Resources/lara',
    ];

    /**
     * Resolve the workspace manifest for an agent.
     *
     * @param  int  $employeeId  Agent employee ID
     */
    public function resolve(int $employeeId): WorkspaceManifest
    {
        $workspacePath = $this->workspacePath($employeeId);
        $isSystemAgent = $this->isSystemAgent($employeeId);
        $frameworkResourcePath = $this->frameworkResourcePath($employeeId);

        $files = [];

        foreach (WorkspaceFileSlot::inLoadOrder() as $slot) {
            $files[] = $this->resolveSlot($slot, $workspacePath, $frameworkResourcePath);
        }

        return new WorkspaceManifest(
            employeeId: $employeeId,
            workspacePath: $workspacePath,
            isSystemAgent: $isSystemAgent,
            frameworkResourcePath: $frameworkResourcePath,
            files: $files,
        );
    }

    /**
     * Resolve a single workspace file slot.
     *
     * Resolution order:
     * 1. Workspace directory: {workspace_path}/{filename}
     * 2. Framework resources: {framework_resource_path}/{filename} (system agents only)
     * 3. Missing
     */
    private function resolveSlot(
        WorkspaceFileSlot $slot,
        string $workspacePath,
        ?string $frameworkResourcePath,
    ): WorkspaceFileEntry {
        $workspaceFile = $workspacePath.'/'.$slot->filename();

        if (is_file($workspaceFile)) {
            return WorkspaceFileEntry::found($slot, $workspaceFile, 'workspace');
        }

        if ($frameworkResourcePath !== null) {
            $frameworkFile = $frameworkResourcePath.'/'.$slot->filename();

            if (is_file($frameworkFile)) {
                return WorkspaceFileEntry::found($slot, $frameworkFile, 'framework');
            }
        }

        return WorkspaceFileEntry::missing($slot);
    }

    /**
     * Get the absolute workspace directory path for an agent.
     */
    private function workspacePath(int $employeeId): string
    {
        return rtrim((string) config('ai.workspace_path'), '/').'/'.$employeeId;
    }

    /**
     * Whether this employee ID maps to a system agent.
     */
    private function isSystemAgent(int $employeeId): bool
    {
        return isset(self::SYSTEM_AGENT_RESOURCES[$employeeId]);
    }

    /**
     * Get the framework resource path for a system agent, or null for user agents.
     */
    private function frameworkResourcePath(int $employeeId): ?string
    {
        $relative = self::SYSTEM_AGENT_RESOURCES[$employeeId] ?? null;

        return $relative !== null ? app_path($relative) : null;
    }
}
