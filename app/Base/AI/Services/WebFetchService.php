<?php

namespace App\Base\AI\Services;

use App\Base\AI\Exceptions\WebFetchResponseTooLargeException;
use App\Base\Support\Str as BlbStr;
use Composer\CaBundle\CaBundle;
use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\UriResolver;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Stateless web-fetch engine with SSRF protection and content extraction.
 */
class WebFetchService
{
    private const CURL_OPT_RESOLVE = 10203;

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
     * @return array{validation_error?: string, request_error?: string, response_too_large?: string, http_status?: int, content?: string, char_count?: int, truncated?: bool}
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
        $acquired = $this->acquireBoundedBody(
            $url,
            $timeoutSeconds,
            $allowPrivateNetwork,
            $hostnameAllowlist,
            $maxResponseBytes,
        );

        if (! isset($acquired['body'])) {
            return $acquired;
        }

        /** @var Response $response */
        $response = $acquired['response'];
        $contentType = strtolower((string) ($response->header('Content-Type') ?? ''));

        return $this->extractReadableContent(
            $acquired['body'],
            $contentType,
            $maxChars,
            $extractMode,
            $acquired['final_url'] ?? $url,
        );
    }

    /**
     * Download a public resource without interpreting its bytes.
     *
     * Unlike {@see fetch()}, this method never truncates a response. It rejects
     * an oversized resource so callers cannot accidentally parse a partial PDF
     * or other binary document as though it were complete.
     *
     * @param  list<string>  $hostnameAllowlist
     * @return array{validation_error?: string, request_error?: string, response_too_large?: string, http_status?: int, body?: string, byte_count?: int, content_type?: string, final_url?: string}
     */
    public function download(
        string $url,
        int $timeoutSeconds,
        int $maxResponseBytes,
        bool $allowPrivateNetwork = false,
        array $hostnameAllowlist = [],
    ): array {
        $acquired = $this->acquireBoundedBody(
            $url,
            $timeoutSeconds,
            $allowPrivateNetwork,
            $hostnameAllowlist,
            $maxResponseBytes,
        );

        if (! isset($acquired['body'])) {
            return $acquired;
        }

        /** @var Response $response */
        $response = $acquired['response'];

        return [
            'body' => $acquired['body'],
            'byte_count' => strlen($acquired['body']),
            'content_type' => strtolower(trim((string) ($response->header('Content-Type') ?? ''))),
            'final_url' => $acquired['final_url'] ?? $url,
        ];
    }

    /**
     * Extract bounded readable content from bytes that have already passed the
     * fetch boundary. This keeps HTML cleanup consistent across web pages and
     * document extraction without causing a second network request.
     *
     * @return array{content: string, char_count: int, truncated: bool}
     */
    public function extractReadableContent(
        string $body,
        string $contentType,
        int $maxChars,
        string $extractMode = 'text',
        ?string $baseUrl = null,
    ): array {
        $content = str_contains(strtolower($contentType), 'text/html')
            ? $this->extractHtml($body, $extractMode, $baseUrl)
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
     * Request a URL through the SSRF-pinned transport, enforce the byte bound,
     * and read the streamed body. Shared by {@see fetch()} and {@see download()}
     * so the redirect-safe request, the Content-Length pre-check, and the
     * streamed body bound live in one place instead of being duplicated.
     *
     * @param  list<string>  $hostnameAllowlist
     * @return array{response?: Response, body?: string, final_url?: string, validation_error?: string, request_error?: string, response_too_large?: string, http_status?: int}
     */
    private function acquireBoundedBody(
        string $url,
        int $timeoutSeconds,
        bool $allowPrivateNetwork,
        array $hostnameAllowlist,
        int $maxResponseBytes,
    ): array {
        $result = $this->requestContent(
            $url,
            $timeoutSeconds,
            $allowPrivateNetwork,
            $hostnameAllowlist,
            $maxResponseBytes,
        );

        if (! isset($result['response'])) {
            return $result;
        }

        /** @var Response $response */
        $response = $result['response'];
        $declaredBytes = $this->declaredContentLength($response);

        if ($declaredBytes !== null && $declaredBytes > $maxResponseBytes) {
            return $this->responseTooLarge($maxResponseBytes);
        }

        try {
            $body = $this->readBoundedBody($response, $maxResponseBytes);
        } catch (Throwable $exception) {
            return $this->causedByResponseSizeLimit($exception)
                ? $this->responseTooLarge($maxResponseBytes)
                : ['request_error' => $exception->getMessage()];
        }

        if ($body === null) {
            return $this->responseTooLarge($maxResponseBytes);
        }

        return [
            'response' => $response,
            'body' => $body,
            'final_url' => $result['final_url'] ?? $url,
        ];
    }

    /**
     * Fetch the URL with SSRF protection that survives DNS rebinding and
     * redirects: every hop (the initial URL and each redirect target) is
     * re-validated, and the connection is pinned to the exact public IP that
     * was validated so no address the guard did not approve is ever contacted.
     *
     * @param  list<string>  $hostnameAllowlist
     * @return array{response?: Response, request_error?: string, response_too_large?: string, http_status?: int, validation_error?: string, final_url?: string}
     */
    private function requestContent(
        string $url,
        int $timeoutSeconds,
        bool $allowPrivateNetwork,
        array $hostnameAllowlist,
        ?int $maxResponseBytes = null,
    ): array {
        $currentUrl = $url;
        $result = ['request_error' => 'Too many redirects (limit '.self::MAX_REDIRECTS.').'];

        for ($hop = 0; $hop <= self::MAX_REDIRECTS; $hop++) {
            $pinning = $this->validatedPinnedIpForUrl(
                $currentUrl,
                $allowPrivateNetwork,
                $hostnameAllowlist,
            );

            if (isset($pinning['validation_error'])) {
                $result = ['validation_error' => $pinning['validation_error']];
                break;
            }

            try {
                $response = $this->sendPinned(
                    $currentUrl,
                    $timeoutSeconds,
                    $pinning['pinned_ip'] ?? null,
                    $maxResponseBytes,
                );
            } catch (Throwable $e) {
                if ($this->causedByResponseSizeLimit($e)) {
                    $result = $this->responseTooLarge($maxResponseBytes ?? 0);
                    break;
                }

                $result = ['request_error' => $e->getMessage()];
                break;
            }

            if ($this->isRedirect($response)) {
                $location = $response->header('Location');

                if ($location === null || $location === '') {
                    $result = ['http_status' => $response->status()];
                    break;
                }

                $currentUrl = (string) UriResolver::resolve(new Uri($currentUrl), new Uri($location));

                continue;
            }

            $result = $response->successful()
                ? ['response' => $response, 'final_url' => $currentUrl]
                : ['http_status' => $response->status()];
            break;
        }

        return $result;
    }

    /**
     * @param  list<string>  $hostnameAllowlist
     * @return array{pinned_ip?: string|null, validation_error?: string}
     */
    private function validatedPinnedIpForUrl(
        string $url,
        bool $allowPrivateNetwork,
        array $hostnameAllowlist,
    ): array {
        $safeCheck = $this->urlSafetyGuard->validate(
            url: $url,
            allowPrivateNetwork: $allowPrivateNetwork,
            hostnameAllowlist: $hostnameAllowlist,
        );

        if ($safeCheck !== true) {
            return ['validation_error' => $safeCheck];
        }

        $parsed = parse_url($url);
        $host = is_array($parsed) ? strtolower((string) ($parsed['host'] ?? '')) : '';

        if (! $this->urlSafetyGuard->pinningRequiredFor($host, $allowPrivateNetwork, $hostnameAllowlist)) {
            return ['pinned_ip' => null];
        }

        $pinnedIp = $this->urlSafetyGuard->pinnedIpFor($host, $allowPrivateNetwork, $hostnameAllowlist);

        return $pinnedIp === null
            ? ['validation_error' => "Blocked: unable to pin hostname {$host} to a public IP address."]
            : ['pinned_ip' => $pinnedIp];
    }

    /**
     * Issue a single request with redirects disabled and the connection pinned
     * to a validated IP for DNS hostnames (via cURL's resolve override, which
     * keeps the original Host/SNI while forcing the target address).
     */
    private function sendPinned(
        string $url,
        int $timeoutSeconds,
        ?string $pinnedIp,
        ?int $maxResponseBytes = null,
    ): Response {
        $request = Http::timeout($timeoutSeconds)
            ->withHeaders(['User-Agent' => 'Belimbing/1.0 (Agent)'])
            ->withOptions([
                'allow_redirects' => false,
                // PHP builds on Windows do not always inherit the OS trust
                // store. Composer's resolver prefers the configured/system
                // bundle and falls back to its maintained Mozilla bundle;
                // certificate and hostname verification remain mandatory.
                'verify' => CaBundle::getSystemCaRootBundlePath(),
            ]);

        if ($maxResponseBytes !== null) {
            $request = $request->withOptions([
                'stream' => true,
                'on_headers' => static function ($response) use ($maxResponseBytes): void {
                    $contentLength = trim($response->getHeaderLine('Content-Length'));

                    if (ctype_digit($contentLength) && (int) $contentLength > $maxResponseBytes) {
                        throw new WebFetchResponseTooLargeException;
                    }
                },
                'progress' => static function ($downloadTotal, $downloadedBytes) use ($maxResponseBytes): void {
                    if ($downloadTotal > $maxResponseBytes || $downloadedBytes > $maxResponseBytes) {
                        throw new WebFetchResponseTooLargeException;
                    }
                },
            ]);
        }

        if ($pinnedIp !== null) {
            $parsed = parse_url($url);
            $host = is_array($parsed) ? strtolower((string) ($parsed['host'] ?? '')) : '';
            $scheme = strtolower((string) ($parsed['scheme'] ?? 'https'));
            $port = (int) ($parsed['port'] ?? ($scheme === 'http' ? 80 : 443));
            $request = $request->withOptions([
                'curl' => [$this->curlResolveOption() => [$this->curlResolveEntry($host, $port, $pinnedIp)]],
            ]);
        }

        return $request->get($url);
    }

    private function isRedirect(Response $response): bool
    {
        return in_array($response->status(), self::REDIRECT_STATUSES, true);
    }

    private function curlResolveEntry(string $host, int $port, string $ip): string
    {
        $address = str_contains($ip, ':') ? '['.$ip.']' : $ip;

        return $host.':'.$port.':'.$address;
    }

    private function curlResolveOption(): int
    {
        return defined('CURLOPT_RESOLVE') ? (int) constant('CURLOPT_RESOLVE') : self::CURL_OPT_RESOLVE;
    }

    private function declaredContentLength(Response $response): ?int
    {
        $contentLength = trim((string) ($response->header('Content-Length') ?? ''));

        return ctype_digit($contentLength) ? (int) $contentLength : null;
    }

    /**
     * Read at most one byte beyond the limit from a streamed response. Returning
     * null signals that the remote body exceeded the accepted size.
     */
    private function readBoundedBody(Response $response, int $maxResponseBytes): ?string
    {
        $stream = $response->toPsrResponse()->getBody();

        if ($stream->isSeekable()) {
            $stream->rewind();
        }

        $body = '';

        while (! $stream->eof() && strlen($body) <= $maxResponseBytes) {
            $remaining = $maxResponseBytes + 1 - strlen($body);
            $chunk = $stream->read(min(8192, $remaining));

            if ($chunk === '') {
                break;
            }

            $body .= $chunk;
        }

        return strlen($body) > $maxResponseBytes ? null : $body;
    }

    /**
     * @return array{response_too_large: string}
     */
    private function responseTooLarge(int $maxResponseBytes): array
    {
        return [
            'response_too_large' => "Response exceeds the {$maxResponseBytes}-byte limit.",
        ];
    }

    private function causedByResponseSizeLimit(Throwable $exception): bool
    {
        do {
            if ($exception instanceof WebFetchResponseTooLargeException) {
                return true;
            }

            $exception = $exception->getPrevious();
        } while ($exception instanceof Throwable);

        return false;
    }

    private function extractHtml(string $html, string $mode, ?string $baseUrl): string
    {
        if ($mode === 'markdown') {
            return $this->extractAsMarkdown($html, $baseUrl);
        }

        return $this->extractAsText($html, $baseUrl);
    }

    private function extractAsText(string $html, ?string $baseUrl): string
    {
        $html = $this->stripNoiseTags($html);
        $html = $this->preserveEmbeddedSources($html, $baseUrl, markdown: false);
        $html = $this->preserveLinks($html, $baseUrl, markdown: false);

        $text = strip_tags($html);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = (string) preg_replace('/[^\S\n]+/', ' ', $text);
        $text = (string) preg_replace('/\n{3,}/', "\n\n", $text);

        return trim($text);
    }

    private function extractAsMarkdown(string $html, ?string $baseUrl): string
    {
        $html = $this->stripNoiseTags($html);
        $html = $this->preserveEmbeddedSources($html, $baseUrl, markdown: true);

        for ($i = 6; $i >= 1; $i--) {
            $prefix = str_repeat('#', $i);
            $html = (string) preg_replace(
                '#<h'.$i.'[^>]*>(.*?)</h'.$i.'>#si',
                "\n\n{$prefix} $1\n\n",
                $html
            );
        }

        $html = $this->preserveLinks($html, $baseUrl, markdown: true);

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

    private function preserveEmbeddedSources(string $html, ?string $baseUrl, bool $markdown): string
    {
        return (string) preg_replace_callback(
            '#<iframe\b([^>]*)>#si',
            function (array $match) use ($baseUrl, $markdown): string {
                $source = $this->htmlAttribute($match[1], 'src');
                $url = $source === null ? null : $this->safeNavigationalUrl($source, $baseUrl);

                if ($url === null) {
                    return '';
                }

                return $markdown
                    ? "\n\n[Embedded source]({$this->markdownUrl($url)})\n\n"
                    : "\n\nEmbedded source: {$url}\n\n";
            },
            $html,
        );
    }

    private function preserveLinks(string $html, ?string $baseUrl, bool $markdown): string
    {
        return (string) preg_replace_callback(
            '#<a\b([^>]*)>(.*?)</a>#si',
            function (array $match) use ($baseUrl, $markdown): string {
                $label = $match[2];
                $target = $this->htmlAttribute($match[1], 'href');
                $url = $target === null ? null : $this->safeNavigationalUrl($target, $baseUrl);

                if ($url === null) {
                    return $label;
                }

                return $markdown
                    ? "[{$label}]({$this->markdownUrl($url)})"
                    : "{$label} [Link: {$url}]";
            },
            $html,
        );
    }

    private function htmlAttribute(string $attributes, string $name): ?string
    {
        $pattern = '/(?:^|\s)'.preg_quote($name, '/').'\s*=\s*(?:(["\'])(.*?)\1|([^\s>]+))/si';

        if (! preg_match($pattern, $attributes, $match)) {
            return null;
        }

        $value = $match[2] !== '' ? $match[2] : ($match[3] ?? '');
        $value = trim(html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8'));

        return $value === '' ? null : $value;
    }

    private function safeNavigationalUrl(string $target, ?string $baseUrl): ?string
    {
        try {
            $targetUri = new Uri($target);
            $resolved = $baseUrl === null
                ? $targetUri
                : UriResolver::resolve(new Uri($baseUrl), $targetUri);
        } catch (Throwable) {
            return null;
        }

        $scheme = strtolower($resolved->getScheme());

        if (
            ! in_array($scheme, ['http', 'https'], true)
            || $resolved->getHost() === ''
            || (string) $resolved->getUserInfo() !== ''
        ) {
            return null;
        }

        return (string) $resolved;
    }

    /**
     * Keep an angle-bracket Markdown destination intact through strip_tags().
     */
    private function markdownUrl(string $url): string
    {
        return '&lt;'.htmlspecialchars($url, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8').'&gt;';
    }
}
