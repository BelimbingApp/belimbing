<?php

use App\Modules\Core\AI\DTO\PromptPackage;
use App\Modules\Core\AI\DTO\PromptSection;
use App\Modules\Core\AI\DTO\WorkspaceFileEntry;
use App\Modules\Core\AI\DTO\WorkspaceManifest;
use App\Modules\Core\AI\DTO\WorkspaceValidationResult;
use App\Modules\Core\AI\Enums\PromptSectionType;
use App\Modules\Core\AI\Enums\WorkspaceFileSlot;
use App\Modules\Core\AI\Services\Workspace\PromptRenderer;
use App\Modules\Core\Employee\Models\Employee;

it('renders sections joined by double newlines', function (): void {
    $package = new PromptPackage(
        sections: [
            new PromptSection('identity', 'You are Lara.', PromptSectionType::Behavioral, 0),
            new PromptSection('context', '{"user":"test"}', PromptSectionType::Operational, 1),
        ],
        manifest: new WorkspaceManifest(
            Employee::LARA_ID, '/tmp', true, null,
            [WorkspaceFileEntry::missing(WorkspaceFileSlot::SystemPrompt)],
        ),
        validation: new WorkspaceValidationResult(true, [], [], []),
    );

    $renderer = new PromptRenderer;
    $result = $renderer->render($package);

    expect($result)->toBe("You are Lara.\n\n{\"user\":\"test\"}");
});

it('renders empty string for empty package', function (): void {
    $package = new PromptPackage(
        sections: [],
        manifest: new WorkspaceManifest(
            Employee::LARA_ID, '/tmp', true, null,
            [WorkspaceFileEntry::missing(WorkspaceFileSlot::SystemPrompt)],
        ),
        validation: new WorkspaceValidationResult(true, [], [], []),
    );

    $renderer = new PromptRenderer;
    expect($renderer->render($package))->toBe('');
});

it('rendering is pure and deterministic', function (): void {
    $sections = [
        new PromptSection('a', 'First section', PromptSectionType::Behavioral, 0),
        new PromptSection('b', 'Second section', PromptSectionType::Operational, 1),
        new PromptSection('c', 'Third section', PromptSectionType::Transient, 2),
    ];

    $package = new PromptPackage(
        sections: $sections,
        manifest: new WorkspaceManifest(
            Employee::LARA_ID, '/tmp', true, null,
            [WorkspaceFileEntry::missing(WorkspaceFileSlot::SystemPrompt)],
        ),
        validation: new WorkspaceValidationResult(true, [], [], []),
    );

    $renderer = new PromptRenderer;

    expect($renderer->render($package))->toBe($renderer->render($package));
});
