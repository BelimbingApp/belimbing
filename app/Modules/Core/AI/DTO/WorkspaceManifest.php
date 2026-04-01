<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\AI\DTO;

use App\Modules\Core\AI\Enums\WorkspaceFileSlot;

/**
 * Resolved workspace state for a single agent.
 *
 * Contains the full file inventory (present and absent) plus
 * metadata about the agent class. Immutable once constructed.
 */
final readonly class WorkspaceManifest
{
    /**
     * @param  int  $employeeId  Agent employee ID
     * @param  string  $workspacePath  Absolute path to the agent's workspace directory
     * @param  bool  $isSystemAgent  Whether this agent is framework-owned (Lara, Kodi)
     * @param  string|null  $frameworkResourcePath  Path to framework-provided resources (system agents only)
     * @param  list<WorkspaceFileEntry>  $files  All resolved file entries in canonical order
     */
    public function __construct(
        public int $employeeId,
        public string $workspacePath,
        public bool $isSystemAgent,
        public ?string $frameworkResourcePath,
        public array $files,
    ) {}

    /**
     * Get an entry by slot.
     */
    public function entry(WorkspaceFileSlot $slot): ?WorkspaceFileEntry
    {
        foreach ($this->files as $file) {
            if ($file->slot === $slot) {
                return $file;
            }
        }

        return null;
    }

    /**
     * Get all entries that exist and contain prompt content.
     *
     * @return list<WorkspaceFileEntry>
     */
    public function presentPromptFiles(): array
    {
        return array_values(array_filter(
            $this->files,
            fn (WorkspaceFileEntry $entry): bool => $entry->exists && $entry->slot->isPromptContent(),
        ));
    }

    /**
     * Diagnostic array representation.
     *
     * @return array{employee_id: int, workspace_path: string, is_system_agent: bool, files: list<array<string, mixed>>}
     */
    public function toArray(): array
    {
        return [
            'employee_id' => $this->employeeId,
            'workspace_path' => $this->workspacePath,
            'is_system_agent' => $this->isSystemAgent,
            'files' => array_map(
                fn (WorkspaceFileEntry $entry): array => $entry->toArray(),
                $this->files,
            ),
        ];
    }
}
