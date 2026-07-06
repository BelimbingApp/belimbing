<?php

namespace App\Base\AI\Services;

use App\Base\Support\Str as BlbStr;
use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\UriResolver;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Stateless web-fetch engine with SSRF protection and content extraction.
 */
class WebFetchService
{
    private const MAX_REDIRECTS = 5;

    private const REDIRECT_STATUSES = [301, 302, 303, 307, 308];

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
        $result = $this->requestContent(
            $url,
            $timeoutSeconds,
            $allowPrivateNetwork,
            $hostnameAllowlist,
        );

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
     * Fetch the URL with SSRF protection that survives DNS rebinding and
     * redirects: every hop (the initial URL and each redirect target) is
     * re-validated, and the connection is pinned to the exact public IP that
     * was validated so no address the guard did not approve is ever contacted.
     *
     * @param  list<string>  $hostnameAllowlist
     * @return array{response?: Response, request_error?: string, http_status?: int, validation_error?: string}
     */
    private function requestContent(
        string $url,
        int $timeoutSeconds,
        bool $allowPrivateNetwork,
        array $hostnameAllowlist,
    ): array {
        $currentUrl = $url;

        for ($hop = 0; $hop <= self::MAX_REDIRECTS; $hop++) {
            $safeCheck = $this->urlSafetyGuard->validate(
                url: $currentUrl,
                allowPrivateNetwork: $allowPrivateNetwork,
                hostnameAllowlist: $hostnameAllowlist,
            );

            if ($safeCheck !== true) {
                return ['validation_error' => $safeCheck];
            }

            try {
                $response = $this->sendPinned(
                    $currentUrl,
                    $timeoutSeconds,
                    $allowPrivateNetwork,
                    $hostnameAllowlist,
                );
            } catch (\Throwable $e) {
                return ['request_error' => $e->getMessage()];
            }

            if (in_array($response->status(), self::REDIRECT_STATUSES, true)) {
                $location = $response->header('Location');

                if ($location === null || $location === '') {
                    return ['http_status' => $response->status()];
                }

                $currentUrl = (string) UriResolver::resolve(new Uri($currentUrl), new Uri($location));

                continue;
            }

            if (! $response->successful()) {
                return ['http_status' => $response->status()];
            }

            return ['response' => $response];
        }

        return ['request_error' => 'Too many redirects (limit '.self::MAX_REDIRECTS.').'];
    }

    /**
     * Issue a single request with redirects disabled and the connection pinned
     * to a validated IP for DNS hostnames (via cURL's resolve override, which
     * keeps the original Host/SNI while forcing the target address).
     *
     * @param  list<string>  $hostnameAllowlist
     */
    private function sendPinned(
        string $url,
        int $timeoutSeconds,
        bool $allowPrivateNetwork,
        array $hostnameAllowlist,
    ): Response {
        $request = Http::timeout($timeoutSeconds)
            ->withHeaders(['User-Agent' => 'Belimbing/1.0 (Agent)'])
            ->withOptions(['allow_redirects' => false]);

        $parsed = parse_url($url);
        $host = is_array($parsed) ? strtolower((string) ($parsed['host'] ?? '')) : '';
        $pinnedIp = $host === ''
            ? null
            : $this->urlSafetyGuard->pinnedIpFor($host, $allowPrivateNetwork, $hostnameAllowlist);

        if ($pinnedIp !== null) {
            $scheme = strtolower((string) ($parsed['scheme'] ?? 'https'));
            $port = (int) ($parsed['port'] ?? ($scheme === 'http' ? 80 : 443));
            $request = $request->withOptions([
                'curl' => [CURLOPT_RESOLVE => [$host.':'.$port.':'.$pinnedIp]],
            ]);
        }

        return $request->get($url);
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
