<?php

use App\Modules\Core\AI\Tools\MemorySearchTool;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\Support\AssertsToolBehavior;
use Tests\TestCase;

uses(TestCase::class, LazilyRefreshDatabase::class, AssertsToolBehavior::class);

beforeEach(function () {
    $this->tool = new MemorySearchTool;
});

describe('tool metadata', function () {
    it('has the expected metadata', function () {
        $this->assertToolMetadata(
            $this->tool,
            'memory_search',
            'ai.tool_memory_search.execute',
            ['query'],
            ['query'],
        );
    });
});

describe('factory method', function () {
    it('returns instance when docs directory exists', function () {
        expect(MemorySearchTool::createIfAvailable())->not->toBeNull();
    });
});

describe('input validation', function () {
    it('rejects empty query', function () {
        $this->assertToolError(['query' => '']);
    });

    it('rejects missing query', function () {
        $this->assertToolError([]);
    });
});

describe('search results', function () {
    it('finds matches for architecture topics', function () {
        $result = $this->tool->execute(['query' => 'architecture module']);
        expect($result)->toContain('match');
    });

    it('finds matches for database topics', function () {
        $result = $this->tool->execute(['query' => 'database migration']);
        expect($result)->toContain('match');
    });

    it('returns no matches for nonsense query', function () {
        $result = $this->tool->execute(['query' => 'xyzzy12345nonexistent']);
        expect($result)->toContain('No matches');
    });

    it('respects max_results parameter', function () {
        $result = $this->tool->execute(['query' => 'the', 'max_results' => 3]);

        expect($result)->not->toBeEmpty();
    });

    it('includes score in results', function () {
        $result = $this->tool->execute(['query' => 'authorization']);
        expect($result)->toContain('[')
            ->and($result)->toContain(']');
    });

    it('includes section headings', function () {
        $result = $this->tool->execute(['query' => 'authorization']);
        expect($result)->toContain('Section:');
    });

    it('includes file path in results', function () {
        $result = $this->tool->execute(['query' => 'authorization']);
        expect($result)->toContain('docs/');
    });
});
