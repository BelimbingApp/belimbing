<?php

use App\Modules\Core\AI\DTO\WorkspaceFileEntry;
use App\Modules\Core\AI\DTO\WorkspaceManifest;
use App\Modules\Core\AI\Enums\WorkspaceFileSlot;
use App\Modules\Core\AI\Services\Workspace\WorkspaceValidator;
use App\Modules\Core\Employee\Models\Employee;

const WORKSPACE_VALIDATOR_TEST_PATH = '/tmp/test';
const WORKSPACE_VALIDATOR_TEST_RESOURCES_PATH = '/tmp/resources';

/**
 * Build a synthetic "found" entry without hitting the filesystem.
 */
function syntheticFound(WorkspaceFileSlot $slot, string $source = 'framework'): WorkspaceFileEntry
{
    return new WorkspaceFileEntry(
        slot: $slot,
        path: '/tmp/synthetic/'.$slot->filename(),
        source: $source,
        exists: true,
        size: 100,
        modifiedAt: time(),
    );
}

it('passes validation when system_prompt is present', function (): void {
    $manifest = new WorkspaceManifest(
        employeeId: Employee::LARA_ID,
        workspacePath: WORKSPACE_VALIDATOR_TEST_PATH,
        isSystemAgent: true,
        frameworkResourcePath: WORKSPACE_VALIDATOR_TEST_RESOURCES_PATH,
        files: [
            syntheticFound(WorkspaceFileSlot::SystemPrompt),
            WorkspaceFileEntry::missing(WorkspaceFileSlot::Operator),
            WorkspaceFileEntry::missing(WorkspaceFileSlot::Tools),
            WorkspaceFileEntry::missing(WorkspaceFileSlot::Extension),
            WorkspaceFileEntry::missing(WorkspaceFileSlot::Memory),
        ],
    );

    $validator = new WorkspaceValidator;
    $result = $validator->validate($manifest);

    expect($result->valid)->toBeTrue()
        ->and($result->errors)->toBeEmpty()
        ->and($result->loadOrder)->toContain(WorkspaceFileSlot::SystemPrompt);
});

it('fails validation when system_prompt is missing', function (): void {
    $manifest = new WorkspaceManifest(
        employeeId: 999,
        workspacePath: WORKSPACE_VALIDATOR_TEST_PATH,
        isSystemAgent: false,
        frameworkResourcePath: null,
        files: [
            WorkspaceFileEntry::missing(WorkspaceFileSlot::SystemPrompt),
            WorkspaceFileEntry::missing(WorkspaceFileSlot::Operator),
            WorkspaceFileEntry::missing(WorkspaceFileSlot::Tools),
            WorkspaceFileEntry::missing(WorkspaceFileSlot::Extension),
            WorkspaceFileEntry::missing(WorkspaceFileSlot::Memory),
        ],
    );

    $validator = new WorkspaceValidator;
    $result = $validator->validate($manifest);

    expect($result->valid)->toBeFalse()
        ->and($result->errors)->not->toBeEmpty()
        ->and($result->errors[0])->toContain('system_prompt');
});

it('produces warnings for missing optional prompt files', function (): void {
    $manifest = new WorkspaceManifest(
        employeeId: Employee::LARA_ID,
        workspacePath: WORKSPACE_VALIDATOR_TEST_PATH,
        isSystemAgent: true,
        frameworkResourcePath: WORKSPACE_VALIDATOR_TEST_RESOURCES_PATH,
        files: [
            syntheticFound(WorkspaceFileSlot::SystemPrompt),
            WorkspaceFileEntry::missing(WorkspaceFileSlot::Operator),
            WorkspaceFileEntry::missing(WorkspaceFileSlot::Tools),
            WorkspaceFileEntry::missing(WorkspaceFileSlot::Extension),
            WorkspaceFileEntry::missing(WorkspaceFileSlot::Memory),
        ],
    );

    $validator = new WorkspaceValidator;
    $result = $validator->validate($manifest);

    expect($result->valid)->toBeTrue()
        ->and($result->warnings)->not->toBeEmpty()
        ->and(count($result->warnings))->toBe(3);
});

it('includes only existing prompt-content slots in load order', function (): void {
    $manifest = new WorkspaceManifest(
        employeeId: Employee::LARA_ID,
        workspacePath: WORKSPACE_VALIDATOR_TEST_PATH,
        isSystemAgent: true,
        frameworkResourcePath: WORKSPACE_VALIDATOR_TEST_RESOURCES_PATH,
        files: [
            syntheticFound(WorkspaceFileSlot::SystemPrompt),
            syntheticFound(WorkspaceFileSlot::Operator, 'workspace'),
            WorkspaceFileEntry::missing(WorkspaceFileSlot::Tools),
            WorkspaceFileEntry::missing(WorkspaceFileSlot::Extension),
            syntheticFound(WorkspaceFileSlot::Memory, 'workspace'),
        ],
    );

    $validator = new WorkspaceValidator;
    $result = $validator->validate($manifest);

    expect($result->loadOrder)->toBe([
        WorkspaceFileSlot::SystemPrompt,
        WorkspaceFileSlot::Operator,
    ]);

    expect($result->loadOrder)->not->toContain(WorkspaceFileSlot::Memory);
});

it('produces deterministic validation results for same input', function (): void {
    $manifest = new WorkspaceManifest(
        employeeId: Employee::LARA_ID,
        workspacePath: WORKSPACE_VALIDATOR_TEST_PATH,
        isSystemAgent: true,
        frameworkResourcePath: WORKSPACE_VALIDATOR_TEST_RESOURCES_PATH,
        files: [
            syntheticFound(WorkspaceFileSlot::SystemPrompt),
            WorkspaceFileEntry::missing(WorkspaceFileSlot::Operator),
            WorkspaceFileEntry::missing(WorkspaceFileSlot::Tools),
            WorkspaceFileEntry::missing(WorkspaceFileSlot::Extension),
            WorkspaceFileEntry::missing(WorkspaceFileSlot::Memory),
        ],
    );

    $validator = new WorkspaceValidator;
    $result1 = $validator->validate($manifest);
    $result2 = $validator->validate($manifest);

    expect($result1->toArray())->toBe($result2->toArray());
});
