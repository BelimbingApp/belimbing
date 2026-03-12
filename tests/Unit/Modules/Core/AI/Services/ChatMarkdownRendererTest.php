<?php

use App\Modules\Core\AI\Services\ChatMarkdownRenderer;

beforeEach(function () {
    $this->renderer = new ChatMarkdownRenderer;
});

function renderMarkdown(string $markdown): string
{
    return test()->renderer->render($markdown);
}

function assertRenderedMarkdown(string $markdown, array $contains = [], array $notContains = []): void
{
    $html = renderMarkdown($markdown);

    foreach ($contains as $fragment) {
        expect($html)->toContain($fragment);
    }

    foreach ($notContains as $fragment) {
        expect($html)->not->toContain($fragment);
    }
}

dataset('rendered markdown fragments', [
    'paragraphs' => [
        'Hello world',
        ['<p>Hello world</p>'],
    ],
    'bold and italic' => [
        '**bold** and *italic*',
        ['<strong>bold</strong>', '<em>italic</em>'],
    ],
    'headings' => [
        "# Heading 1\n## Heading 2\n### Heading 3",
        ['<h1>Heading 1</h1>', '<h2>Heading 2</h2>', '<h3>Heading 3</h3>'],
    ],
    'code blocks' => [
        "```php\n\$x = 1;\n```",
        ['<pre>', '<code'],
    ],
    'inline code' => [
        'Use `composer install` to install',
        ['<code>composer install</code>'],
    ],
    'unordered lists' => [
        "- Item one\n- Item two\n- Item three",
        ['<ul>', '<li>Item one</li>', '<li>Item two</li>'],
    ],
    'ordered lists' => [
        "1. First\n2. Second\n3. Third",
        ['<ol>', '<li>First</li>'],
    ],
    'tables' => [
        "| Name | Value |\n|------|-------|\n| foo | bar |",
        ['<table>', '<th>Name</th>', '<td>foo</td>'],
    ],
    'blockquotes' => [
        '> This is a quote',
        ['<blockquote>'],
    ],
    'links with safe attributes' => [
        '[Click here](https://example.com)',
        ['href="https://example.com"', 'target="_blank"', 'rel="noopener noreferrer"'],
    ],
    'strikethrough' => [
        '~~deleted~~',
        ['<del>deleted</del>'],
    ],
    'horizontal rules' => [
        "Above\n\n---\n\nBelow",
        ['<hr'],
    ],
]);

dataset('sanitized markdown fragments', [
    'script tags' => [
        "Hello\n\n<script>alert('xss')</script>\n\nWorld",
        ['Hello', 'World'],
        ['<script>', 'alert'],
    ],
    'event handlers' => [
        '<a href="https://safe.com" onclick="alert(1)">link</a>',
        ['link'],
        ['onclick'],
    ],
    'javascript protocol links' => [
        '[xss](javascript:alert(1))',
        [],
        ['javascript:'],
    ],
    'iframe and form tags' => [
        '<iframe src="https://evil.com"></iframe><form action="/steal"><input></form>',
        [],
        ['<iframe', '<form', '<input'],
    ],
]);

it('renders empty string for blank input', function () {
    expect(renderMarkdown(''))->toBe('');
    expect(renderMarkdown('   '))->toBe('');
});

it('renders markdown fragments', function (string $markdown, array $contains) {
    assertRenderedMarkdown($markdown, $contains);
})->with('rendered markdown fragments');

it('sanitizes unsafe markdown fragments', function (string $markdown, array $contains, array $notContains) {
    assertRenderedMarkdown($markdown, $contains, $notContains);
})->with('sanitized markdown fragments');
