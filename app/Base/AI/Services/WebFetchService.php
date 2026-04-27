<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Base\AI\Services;

use App\Base\Support\Str as BlbStr;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Stateless web-fetch engine with SSRF protection and content extraction.
 */
class WebFetchService
{
    private const MAX_REDIRECTS = 5;

    public function __construct(
        private readonly UrlSafetyGuard $urlSafetyGuard,
    ) {}

    /**
     * Fetch URL content and extract readable text/markdown.
     *
     * @param  string  $url  Target URL
     * @param  int  $timeoutSeconds  HTTP timeout in seconds
     * @param  int  $maxResponseBytes  Maximum bytes to read from response body
     * @param  int  $maxChars  Maximum extracted characters to return
     * @param  string  $extractMode  Extraction mode: text|markdown
     * @param  bool  $allowPrivateNetwork  Allow private/reserved network targets
     * @param  list<string>  $hostnameAllowlist  Hostname patterns allowed for private targets
     * @return array{validation_error?: string, request_error?: string, http_status?: int, content?: string, char_count?: int, truncated?: bool}
     */
    public function fetch(
        string $url,
        int $timeoutSeconds,
        int $maxResponseBytes,
        int $maxChars,
        string $extractMode,
        bool $allowPrivateNetwork = false,
        array $hostnameAllowlist = [],
    ): array {
        $safeCheck = $this->urlSafetyGuard->validate(
            url: $url,
            allowPrivateNetwork: $allowPrivateNetwork,
            hostnameAllowlist: $hostnameAllowlist,
        );

        $result = $safeCheck === true
            ? $this->requestContent($url, $timeoutSeconds)
            : ['validation_error' => $safeCheck];

        if (! isset($result['response'])) {
            return $result;
        }

        /** @var Response $response */
        $response = $result['response'];
        $body = $response->body();

        if (strlen($body) > $maxResponseBytes) {
            $body = substr($body, 0, $maxResponseBytes);
        }

        $contentType = strtolower((string) ($response->header('Content-Type') ?? ''));
        $isHtml = str_contains($contentType, 'text/html');

        $content = $isHtml
            ? $this->extractHtml($body, $extractMode)
            : $body;

        $truncated = false;

        if (mb_strlen($content) > $maxChars) {
            $content = BlbStr::truncate($content, $maxChars, '');
            $truncated = true;
        }

        return [
            'content' => $content,
            'char_count' => mb_strlen($content),
            'truncated' => $truncated,
        ];
    }

    /**
     * @return array{response?: Response, request_error?: string, http_status?: int}
     */
    private function requestContent(string $url, int $timeoutSeconds): array
    {
        try {
            $response = Http::timeout($timeoutSeconds)
                ->maxRedirects(self::MAX_REDIRECTS)
                ->withHeaders(['User-Agent' => 'Belimbing/1.0 (Agent)'])
                ->get($url);
        } catch (\Throwable $e) {
            return ['request_error' => $e->getMessage()];
        }

        if (! $response->successful()) {
            return ['http_status' => $response->status()];
        }

        return ['response' => $response];
    }

    private function extractHtml(string $html, string $mode): string
    {
        if ($mode === 'markdown') {
            return $this->extractAsMarkdown($html);
        }

        return $this->extractAsText($html);
    }

    private function extractAsText(string $html): string
    {
        $html = $this->stripNoiseTags($html);

        $text = strip_tags($html);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = (string) preg_replace('/[^\S\n]+/', ' ', $text);
        $text = (string) preg_replace('/\n{3,}/', "\n\n", $text);

        return trim($text);
    }

    private function extractAsMarkdown(string $html): string
    {
        $html = $this->stripNoiseTags($html);

        for ($i = 6; $i >= 1; $i--) {
            $prefix = str_repeat('#', $i);
            $html = (string) preg_replace(
                '#<h'.$i.'[^>]*>(.*?)</h'.$i.'>#si',
                "\n\n{$prefix} $1\n\n",
                $html
            );
        }

        $html = (string) preg_replace(
            '#<a\s[^>]*href=["\']([^"\']*)["\'][^>]*>(.*?)</a>#si',
            '[$2]($1)',
            $html
        );

        $html = (string) preg_replace('#<li[^>]*>(.*?)</li>#si', "\n- $1", $html);
        $html = (string) preg_replace('#<p[^>]*>(.*?)</p>#si', "\n\n$1\n\n", $html);
        $html = (string) preg_replace('#<br\s*/?\s*>#si', "\n", $html);
        $html = (string) preg_replace('#<(?:strong|b)[^>]*>(.*?)</(?:strong|b)>#si', '**$1**', $html);
        $html = (string) preg_replace('#<(?:em|i)[^>]*>(.*?)</(?:em|i)>#si', '*$1*', $html);

        $text = strip_tags($html);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = (string) preg_replace('/[^\S\n]+/', ' ', $text);
        $text = (string) preg_replace('/\n{3,}/', "\n\n", $text);

        return trim($text);
    }

    private function stripNoiseTags(string $html): string
    {
        $noiseTags = ['script', 'style', 'nav', 'header', 'footer', 'aside'];

        foreach ($noiseTags as $tag) {
            $html = (string) preg_replace('#<'.$tag.'[^>]*>.*?</'.$tag.'>#si', '', $html);
        }

        return $html;
    }
}
