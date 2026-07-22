<?php

use App\Base\AI\Enums\ToolCategory;
use App\Base\AI\Enums\ToolRiskClass;
use App\Modules\Core\AI\DTO\ToolMetadata;
use App\Modules\Core\AI\Services\ToolMetadataRegistry;
use Illuminate\Foundation\Testing\TestCase;

uses(TestCase::class);

it('contains metadata for the core tool surface', function () {
    $registry = app(ToolMetadataRegistry::class);
    $all = $registry->all();

    expect($all)->toHaveKeys([
        'active_page_snapshot',
        'agent_list',
        'artisan',
        'bash',
        'browser',
        'delegate_task',
        'delegation_status',
        'document_analysis',
        'edit',
        'guide',
        'image_analysis',
        'memory_get',
        'memory_search',
        'memory_status',
        'message',
        'navigate',
        'notification',
        'read',
        'schedule_task',
        'search',
        'system_info',
        'visible_nav_menu',
        'web_fetch',
        'web_search',
        'write_js',
    ]);
});

it('returns null for unknown tool name', function () {
    $registry = app(ToolMetadataRegistry::class);

    expect($registry->get('nonexistent_tool'))->toBeNull();
    expect($registry->has('nonexistent_tool'))->toBeFalse();
});

it('provides complete metadata for each tool', function () {
    $registry = app(ToolMetadataRegistry::class);

    foreach ($registry->all() as $name => $metadata) {
        expect($metadata)->toBeInstanceOf(ToolMetadata::class)
            ->and($metadata->name)->toBe($name)
            ->and($metadata->displayName)->not->toBeEmpty()
            ->and($metadata->summary)->not->toBeEmpty()
            ->and($metadata->explanation)->not->toBeEmpty()
            ->and($metadata->category)->toBeInstanceOf(ToolCategory::class)
            ->and($metadata->riskClass)->toBeInstanceOf(ToolRiskClass::class);
    }
});

it('exposes pdftotext configuration on the document extraction workspace', function () {
    $metadata = app(ToolMetadataRegistry::class)->get('document_analysis');

    expect($metadata?->configFields)->toHaveCount(1)
        ->and($metadata?->configFields[0]->key)->toBe('ai.tools.document_analysis.pdftotext_path')
        ->and($metadata?->configFields[0]->label)->toBe('pdftotext executable');
});

it('allows registering custom tool metadata', function () {
    $registry = app(ToolMetadataRegistry::class);

    $custom = new ToolMetadata(
        name: 'custom_test',
        displayName: 'Custom Test Tool',
        summary: 'A test tool',
        explanation: 'Used only in tests',
        category: ToolCategory::DATA,
        riskClass: ToolRiskClass::READ_ONLY,
        capability: 'admin.ai.tool.custom.execute',
        setupRequirements: [],
        testExamples: [],
        healthChecks: [],
        limits: [],
    );

    $registry->register($custom);

    expect($registry->has('custom_test'))->toBeTrue();
    expect($registry->get('custom_test'))->toBe($custom);
});
