<?php

use App\Modules\Core\AI\Tools\ImageAnalysisTool;
use Tests\TestCase;
use Tests\Support\AssertsToolBehavior;

uses(TestCase::class, AssertsToolBehavior::class);

beforeEach(function () {
    $this->tool = new ImageAnalysisTool;
});

dataset('supported image formats', ['jpg', 'jpeg', 'png', 'gif', 'webp']);
dataset('image analysis missing text fields', [
    [['prompt' => 'Describe this image'], 'path'],
    [['path' => '', 'prompt' => 'Describe this image'], 'path'],
    [['path' => '/images/photo.jpg'], 'prompt'],
    [['path' => '/images/photo.jpg', 'prompt' => ''], 'prompt'],
]);
dataset('image analysis accepted urls', [
    ['http://example.com/image'],
    ['https://example.com/images/photo.png'],
    ['https://example.com/image?w=500&h=300'],
]);

describe('tool metadata', function () {
    it('has the expected metadata', function () {
        $this->assertToolMetadata(
            $this->tool,
            'image_analysis',
            'ai.tool_image_analysis.execute',
            ['path', 'prompt'],
            ['path', 'prompt'],
        );
    });
});

describe('input validation', function () {
    it('rejects missing or empty required text fields', function (array $arguments, string $fragment) {
        $this->assertToolError($arguments, $fragment);
    })->with('image analysis missing text fields');

    it('rejects non-string path', function () {
        $result = $this->tool->execute(['path' => 42, 'prompt' => 'Describe this image']);
        expect($result)->toContain('Error');
    });

    it('rejects prompt exceeding max length', function () {
        $result = $this->tool->execute([
            'path' => '/images/photo.jpg',
            'prompt' => str_repeat('x', 5001),
        ]);
        expect($result)->toContain('Error')
            ->and($result)->toContain('exceed');
    });

    it('rejects unsupported image extension', function () {
        $result = $this->tool->execute([
            'path' => '/images/photo.bmp',
            'prompt' => 'Describe this',
        ]);
        expect($result)->toContain('Error')
            ->and($result)->toContain('Unsupported');
    });

    it('rejects unsupported extension with uppercase', function () {
        $result = $this->tool->execute([
            'path' => '/images/photo.BMP',
            'prompt' => 'Describe this',
        ]);
        expect($result)->toContain('Error');
    });

    it('rejects file with no extension', function () {
        $result = $this->tool->execute([
            'path' => '/images/photo',
            'prompt' => 'Describe this',
        ]);
        expect($result)->toContain('Error');
    });
});

describe('supported formats', function () {
    it('accepts supported image formats', function (string $extension) {
        $data = $this->decodeToolExecution([
            'path' => '/images/photo.'.$extension,
            'prompt' => 'Describe',
        ]);

        expect($data['status'])->toBe('analyzed');
    })->with('supported image formats');
});

describe('URL paths', function () {
    it('accepts supported URL paths', function (string $path) {
        $this->assertToolExecutionStatus([
            'path' => $path,
            'prompt' => 'Describe this',
        ], 'analyzed');
    })->with('image analysis accepted urls');
});

describe('stub execution', function () {
    it('returns valid JSON with required fields', function () {
        $result = $this->tool->execute([
            'path' => '/images/photo.jpg',
            'prompt' => 'What is in this image?',
        ]);
        $data = $this->decodeToolResult($result);

        expect($data)->not->toBeNull()
            ->and($data)->toHaveKeys(['action', 'path', 'prompt', 'status', 'message'])
            ->and($data['action'])->toBe('image_analysis')
            ->and($data['status'])->toBe('analyzed');
    });

    it('includes path and prompt in response', function () {
        $result = $this->tool->execute([
            'path' => '/storage/images/chart.png',
            'prompt' => 'Extract data from chart',
        ]);
        $data = $this->decodeToolResult($result);

        expect($data['path'])->toBe('/storage/images/chart.png')
            ->and($data['prompt'])->toBe('Extract data from chart');
    });

    it('returns stub message', function () {
        $result = $this->tool->execute([
            'path' => '/images/photo.jpg',
            'prompt' => 'Describe',
        ]);
        $data = $this->decodeToolResult($result);

        expect($data['message'])->toContain('stub');
    });
});
