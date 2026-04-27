<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\AI\Tools;

use App\Base\AI\Enums\ToolCategory;
use App\Base\AI\Enums\ToolRiskClass;
use App\Base\AI\Services\UrlSafetyGuard;
use App\Base\AI\Services\WebFetchService;
use App\Base\AI\Tools\AbstractTool;
use App\Base\AI\Tools\Concerns\ProvidesToolMetadata;
use App\Base\AI\Tools\Schema\ToolSchemaBuilder;
use App\Base\AI\Tools\ToolResult;

/**
 * Web page fetching and content extraction tool for Agents.
 *
 * Allows a agent to fetch external web pages and extract readable content
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
    use ProvidesToolMetadata;

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

    protected function metadata(): array
    {
        return [
            'display_name' => 'Web Fetch',
            'summary' => 'Fetch and extract content from a public URL with SSRF protection.',
            'explanation' => 'Fetches a web page via HTTP GET and extracts readable content as plain text or markdown. '
                .'SSRF protection blocks requests to private/internal networks by default. '
                .'Useful for reading documentation, articles, and public web pages. '
                .'This tool cannot access internal services or bypass network restrictions.',
            'setup_requirements' => [
                'Outbound HTTP access available',
                'SSRF guard enabled (default)',
            ],
            'test_examples' => [
                [
                    'label' => 'Fetch documentation',
                    'input' => ['url' => 'https://github.com/BelimbingApp/belimbing/blob/main/docs/installation/quickstart.md', 'extract_mode' => 'markdown'],
                ],
                [
                    'label' => 'Fetch as text',
                    'input' => ['url' => 'https://example.com', 'max_chars' => 1000],
                ],
            ],
            'health_checks' => [
                'HTTP client connectivity',
                'SSRF guard operational',
            ],
            'limits' => [
                '5 MB maximum response size',
                '50,000 character content cap (configurable)',
                '30-second timeout',
                '5 redirect maximum',
            ],
        ];
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
        $errorMessage = null;
        $errorCode = null;

        if (isset($result['validation_error'])) {
            $errorMessage = $result['validation_error'];
            $errorCode = 'validation_error';
        } elseif (isset($result['request_error'])) {
            $errorMessage = 'Failed to fetch URL: '.$result['request_error'];
            $errorCode = 'request_error';
        } elseif (isset($result['http_status'])) {
            $errorMessage = 'Failed to fetch URL: HTTP '.$result['http_status'];
            $errorCode = 'http_error';
        }

        if ($errorMessage !== null && $errorCode !== null) {
            return ToolResult::error($errorMessage, $errorCode);
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
