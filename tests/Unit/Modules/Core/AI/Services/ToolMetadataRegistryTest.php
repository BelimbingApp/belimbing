<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

use App\Base\AI\Enums\ToolCategory;
use App\Base\AI\Enums\ToolRiskClass;
use App\Modules\Core\AI\DTO\ToolMetadata;
use App\Modules\Core\AI\Services\ToolMetadataRegistry;
use Illuminate\Foundation\Testing\TestCase;

uses(TestCase::class);

it('contains metadata for all built-in tools', function () {
    $registry = app(ToolMetadataRegistry::class);
    $all = $registry->all();

    // Verify expected tool count (25 built-in tools)
    expect($all)->toHaveCount(25);

    // Spot-check a few well-known tools
    expect($registry->has('query_data'))->toBeTrue();
    expect($registry->has('web_search'))->toBeTrue();
    expect($registry->has('system_info'))->toBeTrue();
    expect($registry->has('bash'))->toBeTrue();
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

it('allows registering custom tool metadata', function () {
    $registry = app(ToolMetadataRegistry::class);

    $custom = new ToolMetadata(
        name: 'custom_test',
        displayName: 'Custom Test Tool',
        summary: 'A test tool',
        explanation: 'Used only in tests',
        category: ToolCategory::DATA,
        riskClass: ToolRiskClass::READ_ONLY,
        capability: 'ai.tool_custom.execute',
        setupRequirements: [],
        testExamples: [],
        healthChecks: [],
        limits: [],
    );

    $registry->register($custom);

    expect($registry->has('custom_test'))->toBeTrue();
    expect($registry->get('custom_test'))->toBe($custom);
});
