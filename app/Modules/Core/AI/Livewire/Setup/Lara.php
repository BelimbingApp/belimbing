<?php

namespace App\Modules\Core\AI\Livewire\Setup;

use App\Base\Support\File as BlbFile;
use App\Base\Support\Json as BlbJson;
use App\Modules\Core\AI\DTO\WorkspaceManifest;
use App\Modules\Core\AI\Enums\WorkspaceFileSlot;
use App\Modules\Core\AI\Services\ConfigResolver;
use App\Modules\Core\AI\Services\LaraInteractiveToolSet;
use App\Modules\Core\AI\Services\LaraTaskRegistry;
use App\Modules\Core\AI\Services\ToolMetadataRegistry;
use App\Modules\Core\AI\Services\ToolReadinessService;
use App\Modules\Core\AI\Services\Workspace\WorkspaceResolver;
use App\Modules\Core\Company\Models\Company;
use App\Modules\Core\Employee\Models\Employee;
use App\Modules\Core\User\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Session;
use Livewire\Component;

/**
 * Lara setup page.
 *
 * Pivoted from a model-picker into an operator-facing harness inspector:
 * shows each prompt-content workspace slot's effective file (framework default
 * or workspace override), with override / edit / revert actions plus an
 * editor that supports preview-of-assembled-prompt and pre-save linting.
 *
 * Model selection lives on the providers page; the picker in chat handles
 * per-conversation choices.
 */
class Lara extends Component
{
    private const AUDIT_SUFFIX = '.audit.json';

    public ?string $editingSlot = null;

    public string $editingContent = '';

    public bool $showEditorModal = false;

    public function mount(): void
    {
        // Idempotent — only provisions when both Licensee company exists and
        // Lara employee record is missing. Safe to call on every mount.
        if (Company::query()->whereKey(Company::LICENSEE_ID)->exists()) {
            Employee::provisionLara();
        }
    }

    public function provisionLara(): void
    {
        if (Employee::provisionLara()) {
            Session::flash('success', __('Lara has been provisioned.'));
        }
    }

    /**
     * Copy a slot's framework default into the workspace, then open the editor.
     */
    public function overrideSlot(string $slot): void
    {
        $slotEnum = WorkspaceFileSlot::tryFrom($slot);

        if ($slotEnum === null || ! $slotEnum->isPromptContent()) {
            return;
        }

        $manifest = app(WorkspaceResolver::class)->resolve(Employee::LARA_ID);
        $entry = $manifest->entry($slotEnum);

        if ($entry === null || ! $entry->exists) {
            return;
        }

        $content = is_file($entry->path) ? (string) file_get_contents($entry->path) : '';
        $workspaceTarget = rtrim($manifest->workspacePath, '/').'/'.$slotEnum->filename();

        BlbFile::ensureDirectory($manifest->workspacePath);
        BlbFile::put($workspaceTarget, $content);
        $this->writeAuditEntry($slotEnum, strlen($content));

        $this->openSlotEditor($slot);
    }

    /**
     * Open the editor for a slot's current effective content (workspace if overridden, else framework).
     */
    public function editSlot(string $slot): void
    {
        $this->openSlotEditor($slot);
    }

    /**
     * Save the editor's current content to the workspace file for the active slot.
     */
    public function saveSlot(): void
    {
        if ($this->editingSlot === null) {
            return;
        }

        $slotEnum = WorkspaceFileSlot::tryFrom($this->editingSlot);

        if ($slotEnum === null || ! $slotEnum->isPromptContent()) {
            return;
        }

        $workspacePath = $this->workspacePath();
        BlbFile::ensureDirectory($workspacePath);
        BlbFile::put($workspacePath.'/'.$slotEnum->filename(), $this->editingContent);
        $this->writeAuditEntry($slotEnum, strlen($this->editingContent));

        Session::flash('success', __(':slot saved.', ['slot' => __($slotEnum->value)]));
        $this->closeSlotEditor();
    }

    /**
     * Delete the workspace override for a slot, falling back to the framework default.
     */
    public function revertSlot(string $slot): void
    {
        $slotEnum = WorkspaceFileSlot::tryFrom($slot);

        if ($slotEnum === null || ! $slotEnum->isPromptContent()) {
            return;
        }

        $workspacePath = $this->workspacePath();
        $workspaceFile = $workspacePath.'/'.$slotEnum->filename();
        $auditFile = $workspacePath.'/'.$slotEnum->filename().self::AUDIT_SUFFIX;

        if (is_file($workspaceFile)) {
            @unlink($workspaceFile);
            Session::flash('success', __(':slot reverted to framework default.', ['slot' => __($slotEnum->value)]));
        }

        if (is_file($auditFile)) {
            @unlink($auditFile);
        }
    }

    public function closeSlotEditor(): void
    {
        $this->editingSlot = null;
        $this->editingContent = '';
        $this->showEditorModal = false;
    }

    public function updatedShowEditorModal(bool $value): void
    {
        if (! $value) {
            $this->editingSlot = null;
            $this->editingContent = '';
        }
    }

    public function toggleExtraTool(string $toolName): void
    {
        $toolSet = app(LaraInteractiveToolSet::class);
        $toolSet->setExtraToolEnabled($toolName, ! in_array($toolName, $toolSet->extraToolNames(), true));

        Session::flash('success', __('Lara interactive tools updated.'));
    }

    /**
     * Preview the assembled prompt: prompt-content slots concatenated in load
     * order, with the current draft substituted for the slot being edited.
     *
     * Workspace-only assembly — does not include runtime context or page
     * context that the prompt factory injects at request time. The intent is
     * to let the operator see how the static slot files chain together so an
     * edit's structural changes are visible before save.
     */
    public function getAssembledPreviewProperty(): string
    {
        if ($this->editingSlot === null) {
            return '';
        }

        $manifest = app(WorkspaceResolver::class)->resolve(Employee::LARA_ID);
        $sections = [];

        foreach (WorkspaceFileSlot::inLoadOrder() as $slot) {
            if (! $slot->isPromptContent()) {
                continue;
            }

            $content = $slot->value === $this->editingSlot
                ? $this->editingContent
                : $this->readSlotContent($manifest, $slot);

            if (trim($content) === '') {
                continue;
            }

            $sections[] = '## '.ucfirst(str_replace('_', ' ', $slot->value))."\n\n".rtrim($content);
        }

        return implode("\n\n", $sections);
    }

    /**
     * Lint warnings for the current draft. Surfaced beneath the editor as a
     * non-blocking advisory — the operator can still save through them.
     *
     * @return list<string>
     */
    public function getEditorWarningsProperty(): array
    {
        if ($this->editingSlot === null) {
            return [];
        }

        $slotEnum = WorkspaceFileSlot::tryFrom($this->editingSlot);

        if ($slotEnum === null) {
            return [];
        }

        return $this->lintSlotContent($slotEnum, $this->editingContent);
    }

    public function render(): View
    {
        $licenseeExists = Company::query()->whereKey(Company::LICENSEE_ID)->exists();
        $activationState = Employee::laraActivationState();
        $laraExists = $activationState !== null;
        $laraActivated = $activationState === true;

        $resolver = app(ConfigResolver::class);
        $defaultConfig = $laraExists ? $resolver->resolveDefault(Employee::LARA_ID) : null;

        $slots = [];
        $manifest = null;

        if ($laraExists) {
            $manifest = app(WorkspaceResolver::class)->resolve(Employee::LARA_ID);
            $slots = $this->buildSlotRows($manifest);
        }

        $taskSummaries = $laraActivated ? $this->buildTaskSummaries($resolver) : [];
        $toolRows = $laraExists ? $this->buildInteractiveToolRows() : ['enabled' => [], 'available' => []];

        return view('livewire.admin.setup.lara', [
            'licenseeExists' => $licenseeExists,
            'laraExists' => $laraExists,
            'laraActivated' => $laraActivated,
            'defaultConfig' => $defaultConfig,
            'slots' => $slots,
            'workspacePath' => $manifest?->workspacePath,
            'taskSummaries' => $taskSummaries,
            'enabledToolRows' => $toolRows['enabled'],
            'availableToolRows' => $toolRows['available'],
        ]);
    }

    private function openSlotEditor(string $slot): void
    {
        $slotEnum = WorkspaceFileSlot::tryFrom($slot);

        if ($slotEnum === null || ! $slotEnum->isPromptContent()) {
            return;
        }

        $manifest = app(WorkspaceResolver::class)->resolve(Employee::LARA_ID);
        $entry = $manifest->entry($slotEnum);

        $this->editingSlot = $slot;
        $this->editingContent = ($entry !== null && $entry->exists && is_file($entry->path))
            ? (string) file_get_contents($entry->path)
            : '';
        $this->showEditorModal = true;
    }

    private function workspacePath(): string
    {
        return rtrim((string) config('ai.workspace_path'), '/').'/'.Employee::LARA_ID;
    }

    /**
     * @return list<array{slot: string, label: string, source: string, exists: bool, byteSize: int|null, isOverridden: bool, audit: array<string, mixed>|null}>
     */
    private function buildSlotRows(WorkspaceManifest $manifest): array
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

    /**
     * @return array<int, array{label: string, summary: string}>
     */
    private function buildTaskSummaries(ConfigResolver $resolver): array
    {
        return collect(app(LaraTaskRegistry::class)->all())
            ->map(function ($task) use ($resolver): array {
                $config = $resolver->readTaskConfig(Employee::LARA_ID, $task->key) ?? [];
                $provider = $config['provider'] ?? null;
                $model = $config['model'] ?? null;

                $summary = is_string($provider) && is_string($model)
                    ? $provider.'/'.$model
                    : __('Falls back to default');

                return [
                    'label' => $task->label,
                    'summary' => $summary,
                ];
            })
            ->all();
    }

    /**
     * @return array{enabled: list<array<string, mixed>>, available: list<array<string, mixed>>}
     */
    private function buildInteractiveToolRows(): array
    {
        $toolSet = app(LaraInteractiveToolSet::class);
        $metadataRegistry = app(ToolMetadataRegistry::class);
        $readinessService = app(ToolReadinessService::class);
        $enabledExtraNames = $toolSet->extraToolNames();
        $defaultRows = array_map(
            fn (string $toolName): array => $this->toolRow($toolName, $metadataRegistry, $readinessService, true, true),
            $toolSet->defaultToolNames(),
        );
        $enabledExtraRows = array_map(
            fn (string $toolName): array => $this->toolRow($toolName, $metadataRegistry, $readinessService, true, false),
            $enabledExtraNames,
        );
        $availableExtraNames = array_values(array_filter(
            $toolSet->candidateExtraToolNames(),
            fn (string $toolName): bool => ! in_array($toolName, $enabledExtraNames, true),
        ));

        return [
            'enabled' => [...$defaultRows, ...$enabledExtraRows],
            'available' => array_map(
                fn (string $toolName): array => $this->toolRow(
                    $toolName,
                    $metadataRegistry,
                    $readinessService,
                    false,
                    false,
                ),
                $availableExtraNames,
            ),
        ];
    }

    private function toolRow(
        string $toolName,
        ToolMetadataRegistry $metadataRegistry,
        ToolReadinessService $readinessService,
        bool $enabled,
        bool $isDefault,
    ): array {
        $metadata = $metadataRegistry->get($toolName);
        $readiness = $readinessService->readiness($toolName);

        return [
            'name' => $toolName,
            'displayName' => $metadata?->displayName ?? $toolName,
            'summary' => $metadata?->summary ?? '',
            'category' => $metadata?->category->label() ?? __('Unknown'),
            'riskLabel' => $metadata?->riskClass->label() ?? __('Unknown'),
            'riskColor' => $metadata?->riskClass->color() ?? 'default',
            'readinessLabel' => $readiness->label(),
            'readinessColor' => $readiness->color(),
            'enabled' => $enabled,
            'isDefault' => $isDefault,
        ];
    }

    private function readSlotContent(WorkspaceManifest $manifest, WorkspaceFileSlot $slot): string
    {
        $entry = $manifest->entry($slot);

        if ($entry === null || ! $entry->exists || ! is_string($entry->path) || ! is_file($entry->path)) {
            return '';
        }

        return (string) file_get_contents($entry->path);
    }

    /**
     * Persist a minimal audit record for an overridden slot (last editor + time + bytes).
     */
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
     * Cheap, non-blocking checks. Returns a list of warning strings to surface
     * inline beneath the editor.
     *
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
