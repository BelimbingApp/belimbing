<?php

use App\Base\AI\Services\KnowledgeNavigator;
use App\Modules\Core\AI\Tools\GuideTool;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\Support\AssertsToolBehavior;
use Tests\TestCase;

uses(TestCase::class, LazilyRefreshDatabase::class, AssertsToolBehavior::class);

beforeEach(function () {
    $this->tool = new GuideTool(new KnowledgeNavigator);
});

describe('tool metadata', function () {
    it('has the expected metadata', function () {
        $this->assertToolMetadata(
            $this->tool,
            'guide',
            'ai.tool_guide.execute',
            ['topic'],
            ['topic'],
        );
    });
});

describe('input validation', function () {
    it('rejects empty topic', function () {
        $this->assertToolError(['topic' => '']);
    });

    it('rejects missing topic', function () {
        $this->assertToolError([]);
    });
});

describe('search results', function () {
    it('finds architecture documentation', function () {
        $result = $this->tool->execute(['topic' => 'architecture']);
        expect(mb_strtolower($result))->toContain('architecture');
    });

    it('finds authorization documentation', function () {
        $result = $this->tool->execute(['topic' => 'authorization']);
        $lower = mb_strtolower($result);

        expect($lower)->toMatch('/auth(orization)?/');
    });

    it('finds digital worker documentation', function () {
        $result = $this->tool->execute(['topic' => 'digital worker']);
        $lower = mb_strtolower($result);

        expect($lower)->toContain('digital worker');
    });

    it('returns no results message for unknown topic', function () {
        $result = $this->tool->execute(['topic' => 'xyznonexistent']);
        expect($result)->toContain('No documentation found');
    });

    it('lists available topics when no results', function () {
        $result = $this->tool->execute(['topic' => 'xyznonexistent']);
        expect($result)->toContain('Available topics');
    });

    it('respects max_sections parameter', function () {
        $result = $this->tool->execute(['topic' => 'architecture', 'max_sections' => 2]);

        expect($result)->not->toBeEmpty()
            ->and($result)->not->toContain('3.');
    });

    it('includes top match file content', function () {
        $result = $this->tool->execute(['topic' => 'lara']);
        expect($result)->toContain('Top match content');
    });
});
