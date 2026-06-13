<?php

namespace App\Modules\Core\AI\Livewire\Setup;

use App\Modules\Core\AI\Enums\WorkspaceFileSlot;
use App\Modules\Core\AI\Services\ConfigResolver;
use App\Modules\Core\AI\Services\LaraInteractiveToolSet;
use App\Modules\Core\AI\Services\LaraTaskRegistry;
use App\Modules\Core\AI\Services\LaraWorkspaceSlotManager;
use App\Modules\Core\AI\Services\ToolMetadataRegistry;
use App\Modules\Core\AI\Services\ToolReadinessService;
use App\Modules\Core\AI\Services\Workspace\WorkspaceResolver;
use App\Modules\Core\Company\Models\Company;
use App\Modules\Core\Employee\Models\Employee;
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

        if (! app(LaraWorkspaceSlotManager::class)->copyFrameworkDefaultToWorkspace($slotEnum)) {
            return;
        }

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

        app(LaraWorkspaceSlotManager::class)->writeSlot($slotEnum, $this->editingContent);

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

        $deleted = app(LaraWorkspaceSlotManager::class)->deleteSlotOverride($slotEnum);

        if ($deleted) {
            Session::flash('success', __(':slot reverted to framework default.', ['slot' => __($slotEnum->value)]));
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
        return app(LaraWorkspaceSlotManager::class)->assembledPreview($this->editingSlot, $this->editingContent);
    }

    /**
     * Lint warnings for the current draft. Surfaced beneath the editor as a
     * non-blocking advisory — the operator can still save through them.
     *
     * @return list<string>
     */
    public function getEditorWarningsProperty(): array
    {
        return app(LaraWorkspaceSlotManager::class)->editorWarnings($this->editingSlot, $this->editingContent);
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
            $slots = app(LaraWorkspaceSlotManager::class)->slotRows($manifest);
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

        $content = app(LaraWorkspaceSlotManager::class)->editorContent($slotEnum);

        $this->editingSlot = $slot;
        $this->editingContent = $content;
        $this->showEditorModal = true;
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
}
