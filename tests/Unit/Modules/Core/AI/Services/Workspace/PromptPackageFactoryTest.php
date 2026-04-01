<?php

use App\Base\Foundation\Exceptions\BlbConfigurationException;
use App\Modules\Core\AI\DTO\PromptSection;
use App\Modules\Core\AI\DTO\WorkspaceFileEntry;
use App\Modules\Core\AI\DTO\WorkspaceManifest;
use App\Modules\Core\AI\DTO\WorkspaceValidationResult;
use App\Modules\Core\AI\Enums\PromptSectionType;
use App\Modules\Core\AI\Enums\WorkspaceFileSlot;
use App\Modules\Core\AI\Services\Workspace\PromptPackageFactory;
use App\Modules\Core\Employee\Models\Employee;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function (): void {
    $this->tempDir = storage_path('framework/testing/prompt-package-'.uniqid());
    mkdir($this->tempDir, 0755, true);
});

afterEach(function (): void {
    $files = glob($this->tempDir.'/*');
    foreach ($files as $file) {
        if (is_file($file)) {
            unlink($file);
        }
    }
    rmdir($this->tempDir);
});

it('assembles behavioral sections from workspace files in load order', function (): void {
    $systemPromptPath = $this->tempDir.'/system_prompt.md';
    $operatorPath = $this->tempDir.'/operator.md';
    file_put_contents($systemPromptPath, 'You are Lara.');
    file_put_contents($operatorPath, 'Company: Belimbing');

    $manifest = new WorkspaceManifest(
        employeeId: Employee::LARA_ID,
        workspacePath: $this->tempDir,
        isSystemAgent: true,
        frameworkResourcePath: null,
        files: [
            WorkspaceFileEntry::found(WorkspaceFileSlot::SystemPrompt, $systemPromptPath, 'workspace'),
            WorkspaceFileEntry::found(WorkspaceFileSlot::Operator, $operatorPath, 'workspace'),
            WorkspaceFileEntry::missing(WorkspaceFileSlot::Tools),
            WorkspaceFileEntry::missing(WorkspaceFileSlot::Extension),
            WorkspaceFileEntry::missing(WorkspaceFileSlot::Memory),
        ],
    );

    $validation = new WorkspaceValidationResult(
        valid: true,
        errors: [],
        warnings: [],
        loadOrder: [WorkspaceFileSlot::SystemPrompt, WorkspaceFileSlot::Operator],
    );

    $factory = new PromptPackageFactory;
    $package = $factory->build($manifest, $validation);

    expect($package->sections)->toHaveCount(2);
    expect($package->sections[0]->label)->toBe('system_prompt');
    expect($package->sections[0]->content)->toBe('You are Lara.');
    expect($package->sections[0]->type)->toBe(PromptSectionType::Behavioral);
    expect($package->sections[1]->label)->toBe('operator');
    expect($package->sections[1]->content)->toBe('Company: Belimbing');
});

it('wraps extension content with append-only policy preamble', function (): void {
    $systemPromptPath = $this->tempDir.'/system_prompt.md';
    $extensionPath = $this->tempDir.'/extension.md';
    file_put_contents($systemPromptPath, 'Identity');
    file_put_contents($extensionPath, 'Extra rules here');

    $manifest = new WorkspaceManifest(
        employeeId: Employee::LARA_ID,
        workspacePath: $this->tempDir,
        isSystemAgent: true,
        frameworkResourcePath: null,
        files: [
            WorkspaceFileEntry::found(WorkspaceFileSlot::SystemPrompt, $systemPromptPath, 'workspace'),
            WorkspaceFileEntry::missing(WorkspaceFileSlot::Operator),
            WorkspaceFileEntry::missing(WorkspaceFileSlot::Tools),
            WorkspaceFileEntry::found(WorkspaceFileSlot::Extension, $extensionPath, 'workspace'),
            WorkspaceFileEntry::missing(WorkspaceFileSlot::Memory),
        ],
    );

    $validation = new WorkspaceValidationResult(
        valid: true,
        errors: [],
        warnings: [],
        loadOrder: [WorkspaceFileSlot::SystemPrompt, WorkspaceFileSlot::Extension],
    );

    $factory = new PromptPackageFactory;
    $package = $factory->build($manifest, $validation);

    $extensionSection = $package->sections[1];
    expect($extensionSection->label)->toBe('extension')
        ->and($extensionSection->content)->toContain('Prompt extension policy (append-only)')
        ->and($extensionSection->content)->toContain('Extra rules here');
});

it('appends operational and transient sections after behavioral sections', function (): void {
    $systemPromptPath = $this->tempDir.'/system_prompt.md';
    file_put_contents($systemPromptPath, 'Identity');

    $manifest = new WorkspaceManifest(
        employeeId: Employee::LARA_ID,
        workspacePath: $this->tempDir,
        isSystemAgent: true,
        frameworkResourcePath: null,
        files: [
            WorkspaceFileEntry::found(WorkspaceFileSlot::SystemPrompt, $systemPromptPath, 'workspace'),
            WorkspaceFileEntry::missing(WorkspaceFileSlot::Operator),
            WorkspaceFileEntry::missing(WorkspaceFileSlot::Tools),
            WorkspaceFileEntry::missing(WorkspaceFileSlot::Extension),
            WorkspaceFileEntry::missing(WorkspaceFileSlot::Memory),
        ],
    );

    $validation = new WorkspaceValidationResult(
        valid: true,
        errors: [],
        warnings: [],
        loadOrder: [WorkspaceFileSlot::SystemPrompt],
    );

    $operational = [new PromptSection(
        label: 'runtime_context',
        content: '{"user": "test"}',
        type: PromptSectionType::Operational,
        order: 0,
        source: 'test',
    )];

    $transient = [new PromptSection(
        label: 'turn_context',
        content: 'Latest message about X',
        type: PromptSectionType::Transient,
        order: 0,
        source: 'test',
    )];

    $factory = new PromptPackageFactory;
    $package = $factory->build($manifest, $validation, $operational, $transient);

    expect($package->sections)->toHaveCount(3);
    expect($package->sections[0]->type)->toBe(PromptSectionType::Behavioral);
    expect($package->sections[1]->type)->toBe(PromptSectionType::Operational);
    expect($package->sections[2]->type)->toBe(PromptSectionType::Transient);
});

it('skips empty workspace files', function (): void {
    $systemPromptPath = $this->tempDir.'/system_prompt.md';
    $operatorPath = $this->tempDir.'/operator.md';
    file_put_contents($systemPromptPath, 'Identity');
    file_put_contents($operatorPath, '   ');

    $manifest = new WorkspaceManifest(
        employeeId: Employee::LARA_ID,
        workspacePath: $this->tempDir,
        isSystemAgent: true,
        frameworkResourcePath: null,
        files: [
            WorkspaceFileEntry::found(WorkspaceFileSlot::SystemPrompt, $systemPromptPath, 'workspace'),
            WorkspaceFileEntry::found(WorkspaceFileSlot::Operator, $operatorPath, 'workspace'),
            WorkspaceFileEntry::missing(WorkspaceFileSlot::Tools),
            WorkspaceFileEntry::missing(WorkspaceFileSlot::Extension),
            WorkspaceFileEntry::missing(WorkspaceFileSlot::Memory),
        ],
    );

    $validation = new WorkspaceValidationResult(
        valid: true,
        errors: [],
        warnings: [],
        loadOrder: [WorkspaceFileSlot::SystemPrompt, WorkspaceFileSlot::Operator],
    );

    $factory = new PromptPackageFactory;
    $package = $factory->build($manifest, $validation);

    expect($package->sections)->toHaveCount(1);
    expect($package->sections[0]->label)->toBe('system_prompt');
});

it('throws when a resolved file cannot be read', function (): void {
    $fakePath = $this->tempDir.'/nonexistent_but_resolved.md';

    $manifest = new WorkspaceManifest(
        employeeId: Employee::LARA_ID,
        workspacePath: $this->tempDir,
        isSystemAgent: true,
        frameworkResourcePath: null,
        files: [
            new WorkspaceFileEntry(
                WorkspaceFileSlot::SystemPrompt,
                $fakePath,
                'workspace',
                true,
                100,
                time(),
            ),
            WorkspaceFileEntry::missing(WorkspaceFileSlot::Operator),
            WorkspaceFileEntry::missing(WorkspaceFileSlot::Tools),
            WorkspaceFileEntry::missing(WorkspaceFileSlot::Extension),
            WorkspaceFileEntry::missing(WorkspaceFileSlot::Memory),
        ],
    );

    $validation = new WorkspaceValidationResult(
        valid: true,
        errors: [],
        warnings: [],
        loadOrder: [WorkspaceFileSlot::SystemPrompt],
    );

    $factory = new PromptPackageFactory;

    expect(fn () => $factory->build($manifest, $validation))
        ->toThrow(BlbConfigurationException::class);
});

it('reports correct total size and section metadata via describe', function (): void {
    $systemPromptPath = $this->tempDir.'/system_prompt.md';
    file_put_contents($systemPromptPath, 'Hello world');

    $manifest = new WorkspaceManifest(
        employeeId: Employee::LARA_ID,
        workspacePath: $this->tempDir,
        isSystemAgent: true,
        frameworkResourcePath: null,
        files: [
            WorkspaceFileEntry::found(WorkspaceFileSlot::SystemPrompt, $systemPromptPath, 'workspace'),
            WorkspaceFileEntry::missing(WorkspaceFileSlot::Operator),
            WorkspaceFileEntry::missing(WorkspaceFileSlot::Tools),
            WorkspaceFileEntry::missing(WorkspaceFileSlot::Extension),
            WorkspaceFileEntry::missing(WorkspaceFileSlot::Memory),
        ],
    );

    $validation = new WorkspaceValidationResult(
        valid: true,
        errors: [],
        warnings: [],
        loadOrder: [WorkspaceFileSlot::SystemPrompt],
    );

    $factory = new PromptPackageFactory;
    $package = $factory->build($manifest, $validation);
    $description = $package->describe();

    expect($description['section_count'])->toBe(1)
        ->and($description['total_size_bytes'])->toBe(strlen('Hello world'))
        ->and($description['sections'][0]['label'])->toBe('system_prompt')
        ->and($description)->toHaveKey('workspace')
        ->and($description)->toHaveKey('validation')
        ->and($description['sections'][0])->not->toHaveKey('content');
});
