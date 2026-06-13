<?php

namespace App\Modules\Core\AI\Services;

use App\Base\Support\File as BlbFile;
use App\Base\Support\Json as BlbJson;
use App\Modules\Core\AI\DTO\WorkspaceManifest;
use App\Modules\Core\AI\Enums\WorkspaceFileSlot;
use App\Modules\Core\AI\Services\Workspace\WorkspaceResolver;
use App\Modules\Core\Employee\Models\Employee;
use App\Modules\Core\User\Models\User;

class LaraWorkspaceSlotManager
{
    private const AUDIT_SUFFIX = '.audit.json';

    public function copyFrameworkDefaultToWorkspace(WorkspaceFileSlot $slot): bool
    {
        $manifest = $this->manifest();
        $entry = $manifest->entry($slot);

        if ($entry === null || ! $entry->exists) {
            return false;
        }

        $content = is_file($entry->path) ? (string) file_get_contents($entry->path) : '';

        $this->writeSlot($slot, $content);

        return true;
    }

    public function writeSlot(WorkspaceFileSlot $slot, string $content): void
    {
        $workspacePath = $this->workspacePath();

        BlbFile::ensureDirectory($workspacePath);
        BlbFile::put($workspacePath.'/'.$slot->filename(), $content);
        $this->writeAuditEntry($slot, strlen($content));
    }

    public function deleteSlotOverride(WorkspaceFileSlot $slot): bool
    {
        $workspacePath = $this->workspacePath();
        $workspaceFile = $workspacePath.'/'.$slot->filename();
        $auditFile = $workspacePath.'/'.$slot->filename().self::AUDIT_SUFFIX;
        $deleted = false;

        if (is_file($workspaceFile)) {
            @unlink($workspaceFile);
            $deleted = true;
        }

        if (is_file($auditFile)) {
            @unlink($auditFile);
        }

        return $deleted;
    }

    public function editorContent(WorkspaceFileSlot $slot): string
    {
        return $this->readSlotContent($this->manifest(), $slot);
    }

    /**
     * @return list<array{slot: string, label: string, source: string, exists: bool, byteSize: int|null, isOverridden: bool, audit: array<string, mixed>|null}>
     */
    public function slotRows(WorkspaceManifest $manifest): array
    {
        $rows = [];

        foreach (WorkspaceFileSlot::inLoadOrder() as $slot) {
            if (! $slot->isPromptContent()) {
                continue;
            }

            $entry = $manifest->entry($slot);
            $exists = $entry !== null && $entry->exists;
            $source = $entry?->source ?? 'missing';

            $rows[] = [
                'slot' => $slot->value,
                'label' => __(ucfirst(str_replace('_', ' ', $slot->value))),
                'filename' => $slot->filename(),
                'source' => $source,
                'exists' => $exists,
                'isOverridden' => $source === 'workspace',
                'byteSize' => $entry?->size,
                'audit' => $source === 'workspace' ? $this->readAuditEntry($slot) : null,
            ];
        }

        return $rows;
    }

    public function assembledPreview(?string $editingSlot, string $editingContent): string
    {
        if ($editingSlot === null) {
            return '';
        }

        $manifest = $this->manifest();
        $sections = [];

        foreach (WorkspaceFileSlot::inLoadOrder() as $slot) {
            if (! $slot->isPromptContent()) {
                continue;
            }

            $content = $slot->value === $editingSlot
                ? $editingContent
                : $this->readSlotContent($manifest, $slot);

            if (trim($content) === '') {
                continue;
            }

            $sections[] = '## '.ucfirst(str_replace('_', ' ', $slot->value))."\n\n".rtrim($content);
        }

        return implode("\n\n", $sections);
    }

    /**
     * @return list<string>
     */
    public function editorWarnings(?string $editingSlot, string $editingContent): array
    {
        if ($editingSlot === null) {
            return [];
        }

        $slot = WorkspaceFileSlot::tryFrom($editingSlot);

        if ($slot === null) {
            return [];
        }

        return $this->lintSlotContent($slot, $editingContent);
    }

    private function workspacePath(): string
    {
        return rtrim((string) config('ai.workspace_path'), '/').'/'.Employee::LARA_ID;
    }

    private function manifest(): WorkspaceManifest
    {
        return app(WorkspaceResolver::class)->resolve(Employee::LARA_ID);
    }

    private function readSlotContent(WorkspaceManifest $manifest, WorkspaceFileSlot $slot): string
    {
        $entry = $manifest->entry($slot);

        if ($entry === null || ! $entry->exists || ! is_string($entry->path) || ! is_file($entry->path)) {
            return '';
        }

        return (string) file_get_contents($entry->path);
    }

    private function writeAuditEntry(WorkspaceFileSlot $slot, int $byteSize): void
    {
        $user = auth()->user();
        $payload = [
            'user_id' => $user instanceof User ? $user->id : null,
            'user_name' => $user instanceof User ? $user->name : null,
            'edited_at' => now()->toIso8601String(),
            'byte_size' => $byteSize,
        ];

        BlbFile::put(
            $this->workspacePath().'/'.$slot->filename().self::AUDIT_SUFFIX,
            json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    private function readAuditEntry(WorkspaceFileSlot $slot): ?array
    {
        $path = $this->workspacePath().'/'.$slot->filename().self::AUDIT_SUFFIX;

        if (! is_file($path)) {
            return null;
        }

        $raw = file_get_contents($path);

        if ($raw === false || $raw === '') {
            return null;
        }

        $decoded = BlbJson::decodeArray($raw);

        return $decoded === [] ? null : $decoded;
    }

    /**
     * @return list<string>
     */
    private function lintSlotContent(WorkspaceFileSlot $slot, string $content): array
    {
        $warnings = [];
        $trimmed = trim($content);

        if ($slot->isRequired() && $trimmed === '') {
            $warnings[] = __(':slot is a required slot — saving an empty file will break Lara at runtime.', [
                'slot' => $slot->value,
            ]);
        }

        if ($slot === WorkspaceFileSlot::SystemPrompt && strlen($trimmed) < 100 && $trimmed !== '') {
            $warnings[] = __('System prompt is unusually short (:len bytes). Lara may lose identity, safety, or orchestration guidance.', [
                'len' => strlen($trimmed),
            ]);
        }

        $openCodeFences = preg_match_all('/^```/m', $content);

        if ($openCodeFences !== false && $openCodeFences % 2 !== 0) {
            $warnings[] = __('Unbalanced ``` code fences (:count fence markers). Markdown rendering may break.', [
                'count' => $openCodeFences,
            ]);
        }

        return $warnings;
    }
}
