<?php

use App\Modules\Core\AI\Tools\DocumentAnalysisTool;
use Tests\TestCase;
use Tests\Support\AssertsToolBehavior;

uses(TestCase::class, AssertsToolBehavior::class);

dataset('document analysis missing text fields', [
    [['prompt' => 'Summarize this'], 'path'],
    [['path' => '', 'prompt' => 'Summarize this'], 'path'],
    [['path' => '/docs/report.pdf'], 'prompt'],
    [['path' => '/docs/report.pdf', 'prompt' => ''], 'prompt'],
]);

dataset('document analysis accepted pages', [
    ['1'],
    ['1-5'],
    ['1,3,7'],
    ['1-3,5,8-10'],
]);

beforeEach(function () {
    $this->tool = new DocumentAnalysisTool;
});

describe('tool metadata', function () {
    it('has the expected metadata', function () {
        $this->assertToolMetadata(
            $this->tool,
            'document_analysis',
            'ai.tool_document_analysis.execute',
            ['path', 'prompt', 'pages', 'model'],
            ['path', 'prompt'],
        );
    });
});

describe('input validation', function () {
    it('rejects missing or empty required text fields', function (array $arguments, string $fragment) {
        $this->assertToolError($arguments, $fragment);
    })->with('document analysis missing text fields');

    it('rejects non-string path', function () {
        $result = $this->tool->execute(['path' => 123, 'prompt' => 'Summarize this']);
        expect($result)->toContain('Error');
    });

    it('rejects missing prompt', function () {
        $this->assertToolError(['path' => '/docs/report.pdf']);
    });

    it('rejects empty prompt', function () {
        $this->assertToolError(['path' => '/docs/report.pdf', 'prompt' => '']);
    });

    it('rejects prompt exceeding max length', function () {
        $result = $this->tool->execute([
            'path' => '/docs/report.pdf',
            'prompt' => str_repeat('x', 5001),
        ]);
        expect($result)->toContain('Error')
            ->and($result)->toContain('exceed');
    });

    it('rejects invalid pages format with letters', function () {
        $result = $this->tool->execute([
            'path' => '/docs/report.pdf',
            'prompt' => 'Summarize this',
            'pages' => 'abc',
        ]);
        expect($result)->toContain('Error')
            ->and($result)->toContain('pages');
    });

    it('rejects invalid pages format with spaces', function () {
        $result = $this->tool->execute([
            'path' => '/docs/report.pdf',
            'prompt' => 'Summarize this',
            'pages' => '1 - 5',
        ]);
        expect($result)->toContain('Error');
    });

    it('rejects pages exceeding max length', function () {
        $result = $this->tool->execute([
            'path' => '/docs/report.pdf',
            'prompt' => 'Summarize this',
            'pages' => str_repeat('1,', 60).'1',
        ]);
        expect($result)->toContain('Error')
            ->and($result)->toContain('exceed');
    });

    it('rejects non-string pages', function () {
        $result = $this->tool->execute([
            'path' => '/docs/report.pdf',
            'prompt' => 'Summarize this',
            'pages' => 5,
        ]);
        expect($result)->toContain('Error');
    });
});

describe('pages format validation', function () {
    it('accepts supported page selectors', function (string $pages) {
        $data = $this->assertToolExecutionStatus([
            'path' => '/docs/report.pdf',
            'prompt' => 'Summarize this',
            'pages' => $pages,
        ], 'analyzed');

        expect($data['pages'])->toBe($pages);
    })->with('document analysis accepted pages');
});

describe('stub execution', function () {
    it('returns valid JSON with required fields', function () {
        $result = $this->tool->execute([
            'path' => '/docs/report.pdf',
            'prompt' => 'Summarize this document',
        ]);
        $data = $this->decodeToolResult($result);

        expect($data)->not->toBeNull()
            ->and($data)->toHaveKeys(['action', 'path', 'prompt', 'status', 'message'])
            ->and($data['action'])->toBe('document_analysis')
            ->and($data['status'])->toBe('analyzed');
    });

    it('includes path and prompt in response', function () {
        $result = $this->tool->execute([
            'path' => '/storage/docs/contract.pdf',
            'prompt' => 'Extract key dates',
        ]);
        $data = $this->decodeToolResult($result);

        expect($data['path'])->toBe('/storage/docs/contract.pdf')
            ->and($data['prompt'])->toBe('Extract key dates');
    });

    it('includes pages when provided', function () {
        $data = $this->assertToolExecutionStatus([
            'path' => '/docs/report.pdf',
            'prompt' => 'Summarize',
            'pages' => '1-3',
        ], 'analyzed');

        expect($data)->toHaveKey('pages')
            ->and($data['pages'])->toBe('1-3');
    });

    it('excludes pages when not provided', function () {
        $result = $this->tool->execute([
            'path' => '/docs/report.pdf',
            'prompt' => 'Summarize',
        ]);
        $data = $this->decodeToolResult($result);

        expect($data)->not->toHaveKey('pages');
    });

    it('includes model when provided', function () {
        $result = $this->tool->execute([
            'path' => '/docs/report.pdf',
            'prompt' => 'Summarize',
            'model' => 'claude-3-opus',
        ]);
        $data = json_decode($result, true);

        expect($data)->toHaveKey('model')
            ->and($data['model'])->toBe('claude-3-opus');
    });

    it('excludes model when not provided', function () {
        $result = $this->tool->execute([
            'path' => '/docs/report.pdf',
            'prompt' => 'Summarize',
        ]);
        $data = json_decode($result, true);

        expect($data)->not->toHaveKey('model');
    });

    it('returns stub message', function () {
        $result = $this->tool->execute([
            'path' => '/docs/report.pdf',
            'prompt' => 'Summarize',
        ]);
        $data = json_decode($result, true);

        expect($data['message'])->toContain('stub');
    });

    it('trims whitespace from inputs', function () {
        $result = $this->tool->execute([
            'path' => '  /docs/report.pdf  ',
            'prompt' => '  Summarize this  ',
        ]);
        $data = json_decode($result, true);

        expect($data['path'])->toBe('/docs/report.pdf')
            ->and($data['prompt'])->toBe('Summarize this');
    });

    it('handles empty pages string as no pages', function () {
        $result = $this->tool->execute([
            'path' => '/docs/report.pdf',
            'prompt' => 'Summarize',
            'pages' => '  ',
        ]);
        $data = json_decode($result, true);

        expect($data)->not->toHaveKey('pages');
    });
});
