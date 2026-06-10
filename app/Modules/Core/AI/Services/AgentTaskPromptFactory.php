<?php

namespace App\Modules\Core\AI\Services;

use App\Base\Foundation\Exceptions\BlbConfigurationException;
use App\Modules\Core\AI\Contracts\AgentTaskContextContributor;
use App\Modules\Core\AI\DTO\PromptPackage;
use App\Modules\Core\AI\DTO\PromptSection;
use App\Modules\Core\AI\DTO\WorkspaceManifest;
use App\Modules\Core\AI\DTO\WorkspaceValidationResult;
use App\Modules\Core\AI\Enums\AIErrorCode;
use App\Modules\Core\AI\Enums\PromptSectionType;
use App\Modules\Core\AI\Enums\WorkspaceFileSlot;
use App\Modules\Core\AI\Models\OperationDispatch;
use App\Modules\Core\AI\Services\Workspace\PromptPackageFactory;
use App\Modules\Core\AI\Services\Workspace\WorkspaceResolver;
use App\Modules\Core\AI\Services\Workspace\WorkspaceValidator;
use Illuminate\Database\Eloquent\Model;

/**
 * Prompt package factory for queued delegated agent tasks.
 *
 * Builds workspace-driven prompt packages for the dispatched employee
 * and appends dispatch/entity operational context needed by the task.
 */
class AgentTaskPromptFactory
{
    private const GENERIC_SYSTEM_PROMPT_RELATIVE_PATH = 'Modules/Core/AI/Resources/agent-task/system_prompt.md';

    /**
     * @param  iterable<AgentTaskContextContributor>  $contextContributors  Entity context contributors tagged by owning modules
     */
    public function __construct(
        private readonly WorkspaceResolver $workspaceResolver,
        private readonly WorkspaceValidator $workspaceValidator,
        private readonly PromptPackageFactory $packageFactory,
        private readonly iterable $contextContributors = [],
    ) {}

    /**
     * Build the full prompt package for a dispatched agent task.
     *
     * @param  OperationDispatch  $dispatch  The dispatch record
     * @param  Model|null  $entity  Associated domain entity (ticket, case, etc.)
     */
    public function buildPackage(OperationDispatch $dispatch, ?Model $entity = null): PromptPackage
    {
        $manifest = $this->workspaceResolver->resolve($dispatch->employee_id);
        $validation = $this->workspaceValidator->validate($manifest);

        if (! $validation->valid) {
            if ($this->shouldUseGenericFallback($manifest, $validation)) {
                return $this->fallbackPackage($dispatch, $entity, $manifest, $validation);
            }

            throw new BlbConfigurationException(
                'Agent task workspace validation failed: '.implode('; ', $validation->errors),
                AIErrorCode::WORKSPACE_VALIDATION_FAILED,
                ['errors' => $validation->errors, 'employee_id' => $dispatch->employee_id],
            );
        }

        return $this->packageFactory->build(
            manifest: $manifest,
            validation: $validation,
            operationalSections: $this->operationalSections($dispatch, $entity),
        );
    }

    private function shouldUseGenericFallback(WorkspaceManifest $manifest, WorkspaceValidationResult $validation): bool
    {
        $systemPromptEntry = $manifest->entry(WorkspaceFileSlot::SystemPrompt);

        return ! $manifest->isSystemAgent
            && ($systemPromptEntry === null || ! $systemPromptEntry->exists)
            && count($validation->errors) === 1
            && str_contains($validation->errors[0], WorkspaceFileSlot::SystemPrompt->filename());
    }

    private function fallbackPackage(
        OperationDispatch $dispatch,
        ?Model $entity,
        WorkspaceManifest $manifest,
        WorkspaceValidationResult $validation,
    ): PromptPackage {
        $sections = [
            new PromptSection(
                label: WorkspaceFileSlot::SystemPrompt->value,
                content: $this->loadGenericSystemPrompt(),
                type: PromptSectionType::Behavioral,
                order: 0,
                source: 'framework:'.app_path(self::GENERIC_SYSTEM_PROMPT_RELATIVE_PATH),
            ),
        ];

        foreach ($this->operationalSections($dispatch, $entity) as $index => $section) {
            $sections[] = new PromptSection(
                label: $section->label,
                content: $section->content,
                type: $section->type,
                order: $index + 1,
                source: $section->source,
            );
        }

        return new PromptPackage(
            sections: $sections,
            manifest: $manifest,
            validation: new WorkspaceValidationResult(
                valid: true,
                errors: [],
                warnings: [
                    ...$validation->warnings,
                    'Used the framework generic delegated-agent prompt because the agent workspace is missing system_prompt.md.',
                ],
                loadOrder: [WorkspaceFileSlot::SystemPrompt],
            ),
        );
    }

    /**
     * @return list<PromptSection>
     */
    private function operationalSections(OperationDispatch $dispatch, ?Model $entity): array
    {
        $sections = [];

        if ($entity !== null) {
            foreach ($this->contextContributors as $contributor) {
                if ($contributor->supports($entity)) {
                    $sections[] = $contributor->section($entity);
                }
            }
        }

        $sections[] = $this->dispatchSection($dispatch);

        return $sections;
    }

    private function dispatchSection(OperationDispatch $dispatch): PromptSection
    {
        $context = [
            'dispatch_id' => $dispatch->id,
            'task' => $dispatch->task,
            'employee_id' => $dispatch->employee_id,
            'acting_for_user_id' => $dispatch->acting_for_user_id,
        ];

        $encoded = json_encode($context, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return new PromptSection(
            label: 'dispatch_context',
            content: "Dispatch context (JSON):\n".$encoded,
            type: PromptSectionType::Operational,
            order: 1,
            source: 'agent_task_dispatch_context',
        );
    }

    private function loadGenericSystemPrompt(): string
    {
        $path = app_path(self::GENERIC_SYSTEM_PROMPT_RELATIVE_PATH);
        $content = is_file($path) ? file_get_contents($path) : false;

        if (! is_string($content) || trim($content) === '') {
            return 'You are executing a delegated Belimbing agent task. Stay focused on the assigned work, use the provided context, and keep the result production-grade.';
        }

        return trim($content);
    }
}
