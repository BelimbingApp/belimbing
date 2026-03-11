<?php

use App\Modules\Core\AI\Tools\MemoryGetTool;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\Support\AssertsToolBehavior;
use Tests\TestCase;

uses(TestCase::class, LazilyRefreshDatabase::class, AssertsToolBehavior::class);

beforeEach(function () {
    $this->tool = new MemoryGetTool;
});

describe('tool metadata', function () {
    it('has the expected metadata', function () {
        $this->assertToolMetadata(
            $this->tool,
            'memory_get',
            'ai.tool_memory_get.execute',
            ['path'],
            ['path'],
        );
    });
});

describe('input validation', function () {
    it('rejects empty path', function () {
        $this->assertToolError(['path' => '']);
    });

    it('rejects missing path', function () {
        $this->assertToolError([]);
    });

    it('rejects absolute paths', function () {
        $result = (string) $this->tool->execute(['path' => '/etc/passwd']);
        expect($result)->toContain('absolute');
    });

    it('rejects directory traversal', function () {
        $result = (string) $this->tool->execute(['path' => '../../../etc/passwd']);
        expect($result)->toContain('traversal');
    });

    it('rejects null bytes', function () {
        $result = (string) $this->tool->execute(['path' => "file\0.md"]);
        expect($result)->toContain('null bytes');
    });
});

describe('file reading', function () {
    it('reads a file from docs scope', function () {
        $result = (string) $this->tool->execute(['path' => 'brief.md']);

        expect($result)->not->toContain('Error')
            ->and($result)->toContain('brief.md');
    });

    it('returns error for nonexistent file', function () {
        $result = (string) $this->tool->execute(['path' => 'nonexistent-file-xyz.md']);
        expect($result)->toContain('not found');
    });

    it('respects from parameter', function () {
        $result = (string) $this->tool->execute(['path' => 'brief.md', 'from' => 3]);

        expect($result)->not->toContain('# Project Brief: Belimbing')
            ->and($result)->toContain('lines 3-');
    });

    it('respects lines parameter', function () {
        $result = (string) $this->tool->execute(['path' => 'brief.md', 'lines' => 5]);
        expect($result)->toContain('5 lines');
    });

    it('includes footer with scope info', function () {
        $result = (string) $this->tool->execute(['path' => 'brief.md']);
        expect($result)->toContain('docs:brief.md');
    });
});

describe('scope selection', function () {
    it('defaults to docs scope', function () {
        $result = (string) $this->tool->execute(['path' => 'brief.md']);
        expect($result)->toContain('docs:brief.md');
    });

    it('returns error for nonexistent workspace file', function () {
        $result = (string) $this->tool->execute(['path' => 'MEMORY.md', 'scope' => 'workspace']);
        expect($result)->toContain('Error');
    });
});
