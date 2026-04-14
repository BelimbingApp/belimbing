<?php

use App\Modules\Core\AI\Enums\WorkspaceFileSlot;
use App\Modules\Core\AI\Services\Workspace\WorkspaceResolver;
use App\Modules\Core\Employee\Models\Employee;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function (): void {
    $this->workspacePath = storage_path('framework/testing/workspace-resolver-'.uniqid());
    config()->set('ai.workspace_path', $this->workspacePath);
});

afterEach(function (): void {
    File::deleteDirectory($this->workspacePath);
});

it('resolves framework fallback for system agents when workspace directory is absent', function (): void {
    $resolver = new WorkspaceResolver;
    $manifest = $resolver->resolve(Employee::LARA_ID);

    expect($manifest->employeeId)->toBe(Employee::LARA_ID)
        ->and($manifest->isSystemAgent)->toBeTrue()
        ->and($manifest->frameworkResourcePath)->not->toBeNull();

    $systemPrompt = $manifest->entry(WorkspaceFileSlot::SystemPrompt);
    expect($systemPrompt)->not->toBeNull()
        ->and($systemPrompt->exists)->toBeTrue()
        ->and($systemPrompt->source)->toBe('framework');
});

it('prefers workspace files over framework resources', function (): void {
    $agentDir = $this->workspacePath.'/'.Employee::LARA_ID;
    File::ensureDirectoryExists($agentDir);
    file_put_contents($agentDir.'/system_prompt.md', 'Custom workspace prompt');

    $resolver = new WorkspaceResolver;
    $manifest = $resolver->resolve(Employee::LARA_ID);

    $systemPrompt = $manifest->entry(WorkspaceFileSlot::SystemPrompt);
    expect($systemPrompt->exists)->toBeTrue()
        ->and($systemPrompt->source)->toBe('workspace')
        ->and($systemPrompt->path)->toContain($agentDir);
});

it('marks non-system agents as non-system with no framework fallback', function (): void {
    $resolver = new WorkspaceResolver;
    $manifest = $resolver->resolve(999);

    expect($manifest->isSystemAgent)->toBeFalse()
        ->and($manifest->frameworkResourcePath)->toBeNull();

    $systemPrompt = $manifest->entry(WorkspaceFileSlot::SystemPrompt);
    expect($systemPrompt->exists)->toBeFalse()
        ->and($systemPrompt->source)->toBe('none');
});

it('resolves all canonical slots in load order', function (): void {
    $resolver = new WorkspaceResolver;
    $manifest = $resolver->resolve(Employee::LARA_ID);

    $slotValues = array_map(
        fn ($entry) => $entry->slot->value,
        $manifest->files,
    );

    $expectedOrder = array_map(
        fn ($slot) => $slot->value,
        WorkspaceFileSlot::inLoadOrder(),
    );

    expect($slotValues)->toBe($expectedOrder);
});

it('records file size and modification timestamp for existing files', function (): void {
    $resolver = new WorkspaceResolver;
    $manifest = $resolver->resolve(Employee::LARA_ID);

    $systemPrompt = $manifest->entry(WorkspaceFileSlot::SystemPrompt);
    expect($systemPrompt->exists)->toBeTrue()
        ->and($systemPrompt->size)->toBeGreaterThan(0)
        ->and($systemPrompt->modifiedAt)->toBeGreaterThan(0);
});

it('returns null size and modification for missing files', function (): void {
    $resolver = new WorkspaceResolver;
    $manifest = $resolver->resolve(Employee::LARA_ID);

    $operator = $manifest->entry(WorkspaceFileSlot::Operator);
    expect($operator->exists)->toBeFalse()
        ->and($operator->size)->toBeNull()
        ->and($operator->modifiedAt)->toBeNull();
});

it('returns present prompt files correctly', function (): void {
    $resolver = new WorkspaceResolver;
    $manifest = $resolver->resolve(Employee::LARA_ID);

    $presentFiles = $manifest->presentPromptFiles();

    expect($presentFiles)->not->toBeEmpty();

    foreach ($presentFiles as $entry) {
        expect($entry->exists)->toBeTrue()
            ->and($entry->slot->isPromptContent())->toBeTrue();
    }
});
