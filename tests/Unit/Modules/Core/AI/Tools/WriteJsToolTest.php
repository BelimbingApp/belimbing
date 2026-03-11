<?php

use App\Modules\Core\AI\Tools\WriteJsTool;
use Tests\Support\AssertsToolBehavior;
use Tests\TestCase;

uses(TestCase::class, AssertsToolBehavior::class);

beforeEach(function () {
    $this->tool = new WriteJsTool;
});

describe('tool metadata', function () {
    it('has the expected metadata', function () {
        $this->assertToolMetadata(
            $this->tool,
            'write_js',
            'ai.tool_write_js.execute',
            ['script', 'description'],
            ['script', 'description'],
        );
    });
});

describe('input validation', function () {
    it('rejects missing or empty script', function () {
        $this->assertRejectsMissingAndEmptyStringArgument('script', ['description' => 'Test']);
    });

    it('rejects non-string script', function () {
        $result = (string) $this->tool->execute(['script' => 42, 'description' => 'Test']);
        expect($result)->toContain('Error');
    });

    it('rejects missing or empty description', function () {
        $this->assertRejectsMissingAndEmptyStringArgument('description', ['script' => 'console.log("hi")']);
    });

    it('rejects script exceeding max length', function () {
        $result = (string) $this->tool->execute([
            'script' => str_repeat('x', 10001),
            'description' => 'Test',
        ]);
        expect($result)->toContain('Error')
            ->and($result)->toContain('maximum length');
    });

    it('rejects description exceeding max length', function () {
        $result = (string) $this->tool->execute([
            'script' => 'console.log("hi")',
            'description' => str_repeat('x', 501),
        ]);
        expect($result)->toContain('Error')
            ->and($result)->toContain('maximum length');
    });
});

describe('security validation', function () {
    it('blocks unsafe script patterns', function (string $script, string $description) {
        $result = (string) $this->tool->execute([
            'script' => $script,
            'description' => $description,
        ]);
        expect($result)->toContain('Error')
            ->and($result)->toContain('blocked');
    })->with([
        ['eval("alert(1)")', 'Test eval'],
        ['EVAL("alert(1)")', 'Test eval'],
        ['new Function("return 1")()', 'Test Function'],
        ['let c = document.cookie;', 'Test cookie theft'],
        ['localStorage.getItem("key")', 'Test localStorage'],
        ['sessionStorage.setItem("key", "val")', 'Test sessionStorage'],
        ['importScripts("evil.js")', 'Test importScripts'],
        ['import("./module.js").then(m => m.run())', 'Test dynamic import'],
    ]);
});

describe('lara-action execution', function () {
    it('returns lara-action block with script', function () {
        $result = (string) $this->tool->execute([
            'script' => 'console.log("hello")',
            'description' => 'Log a greeting',
        ]);

        expect($result)->toContain('<lara-action>')
            ->and($result)->toContain('</lara-action>')
            ->and($result)->toContain('console.log("hello")');
    });

    it('includes description in response text', function () {
        $result = (string) $this->tool->execute([
            'script' => 'alert("hi")',
            'description' => 'Show an alert',
        ]);

        expect($result)->toContain('Script executed: Show an alert.');
    });

    it('follows NavigateTool lara-action pattern', function () {
        $result = (string) $this->tool->execute([
            'script' => 'document.title = "New Title"',
            'description' => 'Change page title',
        ]);

        expect($result)->toBe(
            '<lara-action>document.title = "New Title"</lara-action>Script executed: Change page title.'
        );
    });

    it('allows safe DOM manipulation', function () {
        $script = 'document.getElementById("app").classList.add("dark")';
        $result = (string) $this->tool->execute([
            'script' => $script,
            'description' => 'Enable dark mode',
        ]);
        $data = null;

        expect($result)->toContain('<lara-action>')
            ->and($result)->toContain($script);
    });

    it('allows clipboard API', function () {
        $script = 'navigator.clipboard.writeText("copied text")';
        $result = (string) $this->tool->execute([
            'script' => $script,
            'description' => 'Copy text to clipboard',
        ]);

        expect($result)->toContain('<lara-action>')
            ->and($result)->toContain($script);
    });

    it('allows fetch API', function () {
        $script = 'fetch("/api/status").then(r => r.json())';
        $result = (string) $this->tool->execute([
            'script' => $script,
            'description' => 'Check API status',
        ]);

        expect($result)->toContain('<lara-action>')
            ->and($result)->toContain($script);
    });
});
