<?php

use App\Modules\Core\AI\Services\Orchestration\SkillPackRegistry;
use App\Modules\Core\AI\Services\Orchestration\SkillSelectionService;
use App\Modules\Core\AI\Services\Runtime\RuntimeSessionContext;
use App\Modules\Core\AI\Tools\LoadSkillTool;
use App\Modules\Core\Employee\Models\Employee;
use Tests\TestCase;

uses(TestCase::class);

it('loads a registered skill body by id', function (): void {
    $registry = app(SkillPackRegistry::class);
    $session = app(RuntimeSessionContext::class);
    $session->remember('employee_id', Employee::LARA_ID);

    $tool = new LoadSkillTool(app(SkillSelectionService::class), $session);

    // Prefer a filesystem skill that should exist in this checkout.
    $candidate = collect($registry->all())
        ->first(fn ($manifest): bool => str_starts_with($manifest->id, 'core.'));

    expect($candidate)->not->toBeNull();

    $result = $tool->execute(['skill_id' => $candidate->id]);

    expect($result->isError)->toBeFalse()
        ->and((string) $result)->toContain('Loaded skill `'.$candidate->id.'`')
        ->and((string) $result)->toContain('## Skill:')
        ->and($session->recall('resolved_skill_pack_ids'))->toContain($candidate->id);
});

it('refuses unknown skill packs', function (): void {
    $session = app(RuntimeSessionContext::class);
    $session->remember('employee_id', Employee::LARA_ID);

    $tool = new LoadSkillTool(app(SkillSelectionService::class), $session);
    $result = $tool->execute(['skill_id' => 'extension.missing.not-a-skill']);

    expect($result->isError)->toBeTrue()
        ->and((string) $result)->toContain('Unknown or unavailable skill pack');
});
