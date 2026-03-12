<?php

use App\Modules\Core\AI\Services\ChatMarkdownRenderer;

beforeEach(function () {
    $this->renderer = new ChatMarkdownRenderer;
});

it('renders empty string for blank input', function () {
    expect($this->renderer->render(''))->toBe('');
    expect($this->renderer->render('   '))->toBe('');
});

it('renders paragraphs', function () {
    $html = $this->renderer->render('Hello world');
    expect($html)->toContain('<p>Hello world</p>');
});

it('renders bold and italic', function () {
    $html = $this->renderer->render('**bold** and *italic*');
    expect($html)->toContain('<strong>bold</strong>');
    expect($html)->toContain('<em>italic</em>');
});

it('renders headings', function () {
    $html = $this->renderer->render("# Heading 1\n## Heading 2\n### Heading 3");
    expect($html)->toContain('<h1>Heading 1</h1>');
    expect($html)->toContain('<h2>Heading 2</h2>');
    expect($html)->toContain('<h3>Heading 3</h3>');
});

it('renders code blocks', function () {
    $html = $this->renderer->render("```php\n\$x = 1;\n```");
    expect($html)->toContain('<pre>');
    expect($html)->toContain('<code');
});

it('renders inline code', function () {
    $html = $this->renderer->render('Use `composer install` to install');
    expect($html)->toContain('<code>composer install</code>');
});

it('renders unordered lists', function () {
    $html = $this->renderer->render("- Item one\n- Item two\n- Item three");
    expect($html)->toContain('<ul>');
    expect($html)->toContain('<li>Item one</li>');
    expect($html)->toContain('<li>Item two</li>');
});

it('renders ordered lists', function () {
    $html = $this->renderer->render("1. First\n2. Second\n3. Third");
    expect($html)->toContain('<ol>');
    expect($html)->toContain('<li>First</li>');
});

it('renders tables', function () {
    $md = "| Name | Value |\n|------|-------|\n| foo | bar |";
    $html = $this->renderer->render($md);
    expect($html)->toContain('<table>');
    expect($html)->toContain('<th>Name</th>');
    expect($html)->toContain('<td>foo</td>');
});

it('renders blockquotes', function () {
    $html = $this->renderer->render('> This is a quote');
    expect($html)->toContain('<blockquote>');
});

it('renders links with safe attributes', function () {
    $html = $this->renderer->render('[Click here](https://example.com)');
    expect($html)->toContain('href="https://example.com"');
    expect($html)->toContain('target="_blank"');
    expect($html)->toContain('rel="noopener noreferrer"');
});

it('strips script tags', function () {
    $html = $this->renderer->render("Hello\n\n<script>alert('xss')</script>\n\nWorld");
    expect($html)->not->toContain('<script>');
    expect($html)->not->toContain('alert');
    expect($html)->toContain('Hello');
    expect($html)->toContain('World');
});

it('strips event handler attributes', function () {
    $html = $this->renderer->render('<a href="https://safe.com" onclick="alert(1)">link</a>');
    expect($html)->not->toContain('onclick');
});

it('strips javascript: protocol links', function () {
    // CommonMark with allow_unsafe_links=false replaces unsafe hrefs
    $html = $this->renderer->render('[xss](javascript:alert(1))');
    expect($html)->not->toContain('javascript:');
});

it('strips iframe and form tags', function () {
    $html = $this->renderer->render('<iframe src="https://evil.com"></iframe><form action="/steal"><input></form>');
    expect($html)->not->toContain('<iframe');
    expect($html)->not->toContain('<form');
    expect($html)->not->toContain('<input');
});

it('renders strikethrough', function () {
    $html = $this->renderer->render('~~deleted~~');
    expect($html)->toContain('<del>deleted</del>');
});

it('renders horizontal rules', function () {
    $html = $this->renderer->render("Above\n\n---\n\nBelow");
    expect($html)->toContain('<hr');
});
