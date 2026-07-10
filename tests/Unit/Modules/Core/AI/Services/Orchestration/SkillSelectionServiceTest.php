<?php

use App\Modules\Core\AI\DTO\Orchestration\SkillPackManifest;
use App\Modules\Core\AI\DTO\Orchestration\SkillPackPromptResource;
use App\Modules\Core\AI\DTO\Orchestration\SkillPackReference;
use App\Modules\Core\AI\DTO\PageContext;
use App\Modules\Core\AI\Enums\SkillPackStatus;
use App\Modules\Core\AI\Services\Orchestration\OrchestrationPolicyService;
use App\Modules\Core\AI\Services\Orchestration\SkillPackRegistry;
use App\Modules\Core\AI\Services\Orchestration\SkillSelectionService;
use App\Modules\Core\AI\Services\PageContextHolder;
use App\Modules\Core\Employee\Models\Employee;
use Tests\TestCase;

uses(TestCase::class);

function registerSkillPack(SkillPackRegistry $registry, string $id, string $name, string $description, string $path): void
{
    $registry->register(new SkillPackManifest(
        id: $id,
        version: '1.0.0',
        name: $name,
        description: $description,
        owner: 'extension:kiat',
        promptResources: [
            new SkillPackPromptResource(
                label: 'body',
                content: '## Skill: '.$name."\n\nProcedure body.",
                order: 300,
            ),
        ],
        references: [
            new SkillPackReference(
                title: $name,
                path: $path,
                summary: $description,
            ),
        ],
        status: SkillPackStatus::Ready,
    ));
}

beforeEach(function (): void {
    $this->registry = new SkillPackRegistry;
    $this->holder = new PageContextHolder;
    $this->selection = new SkillSelectionService(
        $this->registry,
        new OrchestrationPolicyService,
        $this->holder,
    );

    registerSkillPack(
        $this->registry,
        'extension.kiat.verify-annual-report',
        'verify-annual-report',
        'Independently re-extract an ai_draft annual-report entry from the AR PDF.',
        'extensions/kiat/.agents/skills/verify-annual-report/SKILL.md',
    );
    registerSkillPack(
        $this->registry,
        'extension.kiat.advise-refresh',
        'advise-refresh',
        'Refresh lens-based advice notes for held companies.',
        'extensions/kiat/.agents/skills/advise-refresh/SKILL.md',
    );
    registerSkillPack(
        $this->registry,
        'core.pr-review-thread-fix',
        'pr-review-thread-fix',
        'Fix unresolved GitHub PR review comments.',
        '.agents/skills/pr-review-thread-fix/SKILL.md',
    );
});

it('returns an empty selection when the message does not request a skill', function (): void {
    expect($this->selection->selectForTurn(Employee::LARA_ID, 'What is the latest close?'))->toBe([]);
});

it('selects page-suggested skills first', function (): void {
    $page = new PageContext(
        route: 'investment.company-research.show',
        url: 'https://local.blb.lara/investment/company-research/cresbld-8591#advise',
        suggestedSkills: ['extension.kiat.advise-refresh'],
    );

    expect($this->selection->selectForTurn(Employee::LARA_ID, 'refresh this', $page))
        ->toBe(['extension.kiat.advise-refresh']);
});

it('matches skill intent in the user message and respects the auto-select cap', function (): void {
    $selected = $this->selection->selectForTurn(
        Employee::LARA_ID,
        'Use the AR research skill / verify-annual-report on CRESBLD and also advise-refresh',
    );

    expect($selected)->toHaveCount(SkillSelectionService::MAX_AUTO_SELECTED)
        ->and($selected[0])->toBe('extension.kiat.verify-annual-report')
        ->and($selected)->toContain('extension.kiat.advise-refresh');
});

it('resolves packs by trailing slug', function (): void {
    $manifest = $this->selection->resolvePack('verify-annual-report', Employee::LARA_ID);

    expect($manifest)->not->toBeNull()
        ->and($manifest->id)->toBe('extension.kiat.verify-annual-report');
});

it('builds a compact catalog without skill bodies', function (): void {
    $catalog = $this->selection->catalogEntries();

    expect($catalog)->toHaveCount(3)
        ->and($catalog[0])->toHaveKeys(['id', 'name', 'description', 'owner', 'path'])
        ->and($catalog[0])->not->toHaveKey('content');
});
