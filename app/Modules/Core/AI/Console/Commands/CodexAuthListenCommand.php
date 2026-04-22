<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\AI\Console\Commands;

use App\Modules\Core\AI\Services\OpenAiCodexAuth\OpenAiCodexAuthManager;
use Illuminate\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * Temporarily listen on localhost:1455 for the OpenAI Codex OAuth callback.
 *
 * Binds a TCP socket, waits for the browser redirect from OpenAI's authorize
 * endpoint, hands the callback URL to OpenAiCodexAuthManager, serves a branded
 * HTML response to the browser, then exits. Intended for headless/CLI
 * environments where no long-running web server occupies port 1455.
 */
#[AsCommand(name: 'blb:ai:codex:auth-listen')]
class CodexAuthListenCommand extends Command
{
    protected $description = 'Temporarily listen on localhost:1455 for the OpenAI Codex OAuth callback';

    protected $signature = 'blb:ai:codex:auth-listen
                            {--timeout=120 : Seconds to wait before giving up}';

    public function handle(): int
    {
        $timeout = (int) $this->option('timeout');
        $deadline = time() + $timeout;

        $socket = @stream_socket_server('tcp://127.0.0.1:1455', $errno, $errstr);

        if ($socket === false) {
            $this->components->error("Cannot bind port 1455: {$errstr}. Stop any running OpenClaw, Codex CLI, or Pi process first.");

            return self::FAILURE;
        }

        $this->components->info('Listening on http://127.0.0.1:1455/auth/callback …');

        try {
            return $this->waitForCallback($socket, $deadline, $timeout);
        } finally {
            fclose($socket);
        }
    }

    private function waitForCallback($socket, int $deadline, int $timeout): int
    {
        while (time() < $deadline) {
            $remaining = max(1, $deadline - time());
            $conn = @stream_socket_accept($socket, $remaining);

            if ($conn === false) {
                continue;
            }

            $raw = fread($conn, 8192);

            if ($raw === false || $raw === '') {
                fclose($conn);

                continue;
            }

            $result = $this->handleRequest($conn, $raw);

            if ($result !== null) {
                return $result;
            }
        }

        $this->components->warn("Timed out after {$timeout}s.");

        return self::FAILURE;
    }

    private function handleRequest($conn, string $raw): ?int
    {
        $firstLine = strtok($raw, "\r\n");

        if (! preg_match('#^GET\s+(/[^\s]*)\s+HTTP/#', $firstLine, $m)) {
            fclose($conn);

            return null;
        }

        $fullPath = $m[1];
        $parsed = parse_url($fullPath);
        $path = $parsed['path'] ?? '';

        if ($path !== '/auth/callback') {
            $this->sendHttpResponse($conn, 404, '<html><body style="background:#0a0a0a;color:#fafafa;font-family:system-ui;display:flex;justify-content:center;align-items:center;height:100vh;margin:0"><h1>404 — Not Found</h1></body></html>');
            fclose($conn);

            return null;
        }

        parse_str($parsed['query'] ?? '', $query);
        $code = $query['code'] ?? '';
        $state = $query['state'] ?? '';

        if ($code === '' || $state === '') {
            $error = 'Missing code or state parameter in callback URL.';
            $this->sendHttpResponse($conn, 400, $this->errorHtml($error));
            fclose($conn);
            $this->components->error($error);

            return self::FAILURE;
        }

        $url = 'http://localhost:1455/auth/callback?'.http_build_query(['code' => $code, 'state' => $state]);

        try {
            $provider = app(OpenAiCodexAuthManager::class)->completeManualInput($url);
        } catch (\Throwable $e) {
            $this->sendHttpResponse($conn, 500, $this->errorHtml($e->getMessage()));
            fclose($conn);
            $this->components->error('Authentication failed: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->sendHttpResponse($conn, 200, $this->successHtml());
        fclose($conn);
        $this->components->info("OpenAI Codex connected (provider #{$provider->getKey()}).");

        return self::SUCCESS;
    }

    private function sendHttpResponse($conn, int $status, string $html): void
    {
        $reason = match ($status) {
            200 => 'OK',
            400 => 'Bad Request',
            404 => 'Not Found',
            500 => 'Internal Server Error',
            default => 'Error',
        };

        $length = strlen($html);
        $header = "HTTP/1.1 {$status} {$reason}\r\nContent-Type: text/html; charset=utf-8\r\nContent-Length: {$length}\r\nConnection: close\r\n\r\n";

        fwrite($conn, $header.$html);
    }

    private function successHtml(): string
    {
        return <<<'HTML'
        <!DOCTYPE html>
        <html lang="en">
        <head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>BLB — Codex Connected</title></head>
        <body style="margin:0;background:#0a0a0a;color:#fafafa;font-family:system-ui,-apple-system,sans-serif;display:flex;justify-content:center;align-items:center;height:100vh">
        <div style="text-align:center">
        <div style="font-size:4rem;color:#22c55e;margin-bottom:.5rem">✓</div>
        <h1 style="margin:0 0 .5rem;font-size:1.5rem;font-weight:600">OpenAI Codex connected</h1>
        <p style="margin:0;color:#a1a1aa;font-size:.875rem">You can close this tab and return to BLB.</p>
        </div>
        </body>
        </html>
        HTML;
    }

    private function errorHtml(string $message): string
    {
        $escaped = htmlspecialchars($message, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        return <<<HTML
        <!DOCTYPE html>
        <html lang="en">
        <head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>BLB — Authentication Failed</title></head>
        <body style="margin:0;background:#0a0a0a;color:#fafafa;font-family:system-ui,-apple-system,sans-serif;display:flex;justify-content:center;align-items:center;height:100vh">
        <div style="text-align:center">
        <div style="font-size:4rem;color:#ef4444;margin-bottom:.5rem">✗</div>
        <h1 style="margin:0 0 .5rem;font-size:1.5rem;font-weight:600">Authentication failed</h1>
        <p style="margin:0;color:#a1a1aa;font-size:.875rem">{$escaped}</p>
        </div>
        </body>
        </html>
        HTML;
    }
}
