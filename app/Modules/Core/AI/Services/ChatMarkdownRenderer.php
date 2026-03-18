<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\AI\Services;

use Illuminate\Support\Str;

/**
 * Renders LLM Markdown responses into safe HTML for agent chat interfaces.
 *
 * Uses Laravel's Str::markdown() (league/commonmark GFM) for conversion,
 * then sanitizes the output with a strict HTML tag/attribute allowlist.
 * XSS protection is non-negotiable — LLM output is untrusted.
 */
class ChatMarkdownRenderer
{
    /**
     * HTML tags allowed in rendered output.
     *
     * Covers CommonMark + GFM: paragraphs, formatting, headings,
     * code blocks, lists, tables, links, images, blockquotes, and
     * horizontal rules.
     */
    private const ALLOWED_TAGS = [
        'p', 'br', 'hr',
        'strong', 'em', 'del', 's',
        'h1', 'h2', 'h3', 'h4', 'h5', 'h6',
        'pre', 'code',
        'ul', 'ol', 'li',
        'table', 'thead', 'tbody', 'tr', 'th', 'td',
        'a',
        'blockquote',
        'img',
    ];

    /**
     * Attributes allowed per tag. Tags not listed here get no attributes.
     */
    private const ALLOWED_ATTRIBUTES = [
        'a' => ['href', 'title', 'target', 'rel'],
        'img' => ['src', 'alt', 'title'],
        'code' => ['class'],
        'th' => ['align'],
        'td' => ['align'],
    ];

    /**
     * Convert Markdown content to sanitized HTML.
     */
    public function render(string $markdown): string
    {
        if (trim($markdown) === '') {
            return '';
        }

        $html = Str::markdown($markdown, [
            'html_input' => 'strip',
            'allow_unsafe_links' => false,
        ]);

        return $this->sanitize($html);
    }

    /**
     * Sanitize HTML by stripping disallowed tags and attributes.
     *
     * Two-pass approach: strip_tags removes disallowed elements entirely,
     * then DOMDocument removes disallowed attributes from remaining elements.
     * Link hrefs are validated to prevent javascript: protocol injection.
     */
    private function sanitize(string $html): string
    {
        $allowedTagString = implode('', array_map(
            fn (string $tag): string => "<{$tag}>",
            self::ALLOWED_TAGS,
        ));

        $html = strip_tags($html, $allowedTagString);

        return $this->stripDisallowedAttributes($html);
    }

    /**
     * Remove attributes not in the allowlist from all HTML elements.
     */
    private function stripDisallowedAttributes(string $html): string
    {
        if (trim($html) === '') {
            return '';
        }

        $doc = new \DOMDocument;

        // Encode non-ASCII as numeric entities for DOMDocument compatibility (PHP 8.2+).
        $encoded = preg_replace_callback('/[\x80-\x{10FFFF}]/u', function (array $m): string {
            return '&#'.mb_ord($m[0], 'UTF-8').';';
        }, $html) ?? $html;

        @$doc->loadHTML(
            '<div>'.$encoded.'</div>',
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD,
        );

        $this->processNode($doc);

        $body = $doc->getElementsByTagName('div')->item(0);
        if ($body === null) {
            return $html;
        }

        $result = '';
        foreach ($body->childNodes as $child) {
            $result .= $doc->saveHTML($child);
        }

        return $result;
    }

    /**
     * Recursively strip disallowed attributes from a DOM node tree.
     */
    private function processNode(\DOMNode $node): void
    {
        if ($node instanceof \DOMElement) {
            $this->sanitizeAttributes($node);

            if (strtolower($node->tagName) === 'a') {
                $this->validateLink($node);
            }
        }

        foreach ($node->childNodes as $child) {
            $this->processNode($child);
        }
    }

    /**
     * Remove any attributes not in the element's allowlist.
     */
    private function sanitizeAttributes(\DOMElement $el): void
    {
        $allowed = self::ALLOWED_ATTRIBUTES[strtolower($el->tagName)] ?? [];

        $toRemove = [];
        foreach ($el->attributes as $attr) {
            if (! in_array($attr->name, $allowed, true)) {
                $toRemove[] = $attr->name;
            }
        }

        foreach ($toRemove as $attrName) {
            $el->removeAttribute($attrName);
        }
    }

    /**
     * Validate link href protocol; set target/rel for external links.
     */
    private function validateLink(\DOMElement $el): void
    {
        if (! $el->hasAttribute('href')) {
            return;
        }

        $href = trim($el->getAttribute('href'));

        if (! preg_match('#^(https?://|mailto:|/|\#)#i', $href)) {
            $el->removeAttribute('href');
        }

        // External links open in new tab.
        if ($el->hasAttribute('href')) {
            $el->setAttribute('target', '_blank');
            $el->setAttribute('rel', 'noopener noreferrer');
        }
    }
}
