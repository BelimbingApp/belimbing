<?php

use App\Modules\Core\AI\DTO\Orchestration\HookPayload;
use App\Modules\Core\AI\DTO\Orchestration\SkillPackManifest;
use App\Modules\Core\AI\DTO\Orchestration\SkillPackPromptResource;
use App\Modules\Core\AI\DTO\PageContext;
use App\Modules\Core\AI\Enums\HookStage;
use App\Modules\Core\AI\Enums\SkillPackStatus;
use App\Modules\Core\AI\Services\Orchestration\OrchestrationPolicyService;
use App\Modules\Core\AI\Services\Orchestration\SkillContextInjectionHook;
use App\Modules\Core\AI\Services\Orchestration\SkillPackRegistry;
use App\Modules\Core\AI\Services\Orchestration\SkillSelectionService;
use App\Modules\Core\AI\Services\PageContextHolder;
use App\Modules\Core\AI\Services\Runtime\RuntimeSessionContext;
use App\Modules\Core\Employee\Models\Employee;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function (): void {
    $this->registry = new SkillPackRegistry;
    $this->holder = new PageContextHolder;
    $this->session = new RuntimeSessionContext;

    $this->registry->register(new SkillPackManifest(
        id: 'extension.kiat.advise-refresh',
        version: '1.0.0',
        name: 'advise-refresh',
        description: 'Refresh lens-based advice notes for held companies.',
        owner: 'extension:kiat',
        promptResources: [
            new SkillPackPromptResource(
                label: 'body',
                content: "## Skill: advise-refresh\n\nProcedure body.",
                order: 300,
            ),
        ],
        status: SkillPackStatus::Ready,
    ));

    $this->hook = new SkillContextInjectionHook(
        new SkillSelectionService($this->registry, new OrchestrationPolicyService, $this->holder),
        $this->registry,
        $this->holder,
        $this->session,
    );
});

function skillHookPayload(?string $userMessage): HookPayload
{
    return new HookPayload(
        stage: HookStage::PreContextBuild,
        runId: 'run-test',
        employeeId: Employee::LARA_ID,
        data: $userMessage !== null ? ['user_message' => $userMessage] : [],
    );
}

it('runs at PreContextBuild with a stable identifier', function (): void {
    expect($this->hook->stage())->toBe(HookStage::PreContextBuild)
        ->and($this->hook->identifier())->toBe('skill.context-injection');
});

it('noops when no skill is selected for the turn', function (): void {
    $result = $this->hook->execute(skillHookPayload('What is the latest close?'));

    expect($result->promptSections)->toBe([])
        ->and($this->session->recall('resolved_skill_pack_ids'))->toBeNull();
});

it('injects the pack body and records resolved ids for page-suggested skills', function (): void {
    $this->holder->setContext(new PageContext(
        route: 'investment.company-research.show',
        url: 'https://local.blb.lara/investment/company-research/cresbld-8591#advise',
        suggestedSkills: ['extension.kiat.advise-refresh'],
    ));

    $result = $this->hook->execute(skillHookPayload('refresh this'));

    expect($result->handled)->toBeTrue()
        ->and($result->promptSections)->toHaveCount(1)
        ->and($result->promptSections[0])->toContain('## Skill: advise-refresh')
        ->and($result->metadata['resolved_skill_pack_ids'])->toBe(['extension.kiat.advise-refresh'])
        ->and($this->session->recall('resolved_skill_pack_ids'))->toBe(['extension.kiat.advise-refresh']);
});

it('falls back to the session-recalled user message for intent matching', function (): void {
    $this->session->remember('latest_user_message', 'Run the advise-refresh skill for CRESBLD');

    $result = $this->hook->execute(skillHookPayload(null));

    expect($result->promptSections)->toHaveCount(1)
        ->and($result->metadata['resolved_skill_pack_ids'])->toBe(['extension.kiat.advise-refresh']);
});
