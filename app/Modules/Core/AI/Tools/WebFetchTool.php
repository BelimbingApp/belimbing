<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\AI\Tools;

use App\Base\AI\Enums\ToolCategory;
use App\Base\AI\Enums\ToolRiskClass;
use App\Base\AI\Services\UrlSafetyGuard;
use App\Base\AI\Services\WebFetchService;
use App\Base\AI\Tools\AbstractTool;
use App\Base\AI\Tools\Schema\ToolSchemaBuilder;
use App\Base\AI\Tools\ToolResult;

/**
 * Web page fetching and content extraction tool for Digital Workers.
 *
 * Allows a DW to fetch external web pages and extract readable content
 * for research, data gathering, and contextual understanding.
 *
 * Safety: SSRF protection blocks requests to private/internal networks
 * by default. Response size is capped to prevent memory exhaustion.
 * Redirect count is limited to prevent redirect loops.
 *
 * Gated by `ai.tool_web_fetch.execute` authz capability.
 */
class WebFetchTool extends AbstractTool
{
    private const DEFAULT_TIMEOUT_SECONDS = 30;

    private const DEFAULT_MAX_RESPONSE_BYTES = 5242880; // 5MB

    private const DEFAULT_MAX_CHARS = 50000;

    private readonly WebFetchService $webFetchService;

    public function __construct(?WebFetchService $webFetchService = null)
    {
        $this->webFetchService = $webFetchService ?? new WebFetchService(new UrlSafetyGuard);
    }

    public function name(): string
    {
        return 'web_fetch';
    }

    public function description(): string
    {
        return 'Fetch a web page and extract its readable content. '
            .'Use this to read documentation, articles, product pages, or any public URL. '
            .'Returns extracted text content from the page.';
    }

    protected function schema(): ToolSchemaBuilder
    {
        return ToolSchemaBuilder::make()
            ->string('url', 'The URL to fetch (must be http or https).')->required()
            ->integer(
                'max_chars',
                'Maximum characters of content to return (default 50000). '
                    .'Reduce for concise summaries, increase for full-page content.',
                min: 1,
            )
            ->string(
                'extract_mode',
                'Content extraction mode: "text" for plain text (default), '
                    .'"markdown" to preserve headings, links, and formatting.',
                enum: ['text', 'markdown'],
            );
    }

    public function category(): ToolCategory
    {
        return ToolCategory::WEB;
    }

    public function riskClass(): ToolRiskClass
    {
        return ToolRiskClass::EXTERNAL_IO;
    }

    public function requiredCapability(): ?string
    {
        return 'ai.tool_web_fetch.execute';
    }

    protected function handle(array $arguments): ToolResult
    {
        $url = $this->requireString($arguments, 'url', 'URL');
        $maxChars = $this->optionalInt($arguments, 'max_chars', self::DEFAULT_MAX_CHARS, min: 1);
        $extractMode = $this->requireEnum($arguments, 'extract_mode', ['text', 'markdown'], 'text');

        $timeout = (int) config('ai.tools.web_fetch.timeout_seconds', self::DEFAULT_TIMEOUT_SECONDS);
        $maxBytes = (int) config('ai.tools.web_fetch.max_response_bytes', self::DEFAULT_MAX_RESPONSE_BYTES);

        $result = $this->webFetchService->fetch(
            url: $url,
            timeoutSeconds: $timeout,
            maxResponseBytes: $maxBytes,
            maxChars: $maxChars,
            extractMode: $extractMode,
            allowPrivateNetwork: (bool) config('ai.tools.web_fetch.ssrf_allow_private', false),
        );

        return $this->formatFetchResponse($result, $url, $maxChars);
    }

    /**
     * @param  array{validation_error?: string, request_error?: string, http_status?: int, content?: string, char_count?: int, truncated?: bool}  $result
     */
    private function formatFetchResponse(array $result, string $url, int $maxChars): ToolResult
    {
        if (isset($result['validation_error'])) {
            return ToolResult::error($result['validation_error'], 'validation_error');
        }

        if (isset($result['request_error'])) {
            return ToolResult::error('Failed to fetch URL: '.$result['request_error'], 'request_error');
        }

        if (isset($result['http_status'])) {
            return ToolResult::error(
                'Failed to fetch URL: HTTP '.$result['http_status'],
                'http_error',
            );
        }

        $content = $result['content'] ?? '';

        if (($result['truncated'] ?? false) === true) {
            $content .= "\n\n[Content truncated at {$maxChars} characters]";
        }

        $charCount = (int) ($result['char_count'] ?? mb_strlen($content));

        return ToolResult::success(
            "# Content from {$url}\n\n{$content}\n\n---\nFetched {$charCount} characters from {$url}"
        );
    }
}
