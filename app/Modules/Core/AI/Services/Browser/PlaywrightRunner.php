<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\AI\Services\Browser;

use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * Executes browser actions via the Playwright Node.js runner subprocess.
 *
 * Each call launches a fresh Chromium instance (headful or headless based
 * on config), executes a single action, and closes the browser. Designed
 * for per-command invocation; session persistence will be added later.
 *
 * Two execution modes:
 * - **Headless**: synchronous via Symfony Process. PHP waits for the
 *   node process to finish, reads result from stdout.
 * - **Headful**: detached via exec() + setsid. PHP launches the node
 *   process in the background, communicates via temp files (atomic
 *   rename prevents partial reads), and returns immediately. The browser
 *   window stays open until the user closes it or the keep-alive timeout
 *   expires (default 5 minutes).
 *
 * When headful mode is configured, the runner explicitly forwards display
 * server environment variables (DISPLAY, WAYLAND_DISPLAY, XDG_RUNTIME_DIR)
 * to the subprocess and detects available X11 sockets. If no display is
 * available, it transparently falls back to headless mode.
 *
 * The runner script is at resources/core/scripts/browser-runner.mjs.
 */
class PlaywrightRunner
{
    /**
     * Maximum time (seconds) to wait for the Node.js process to complete.
     */
    private const PROCESS_TIMEOUT = 30;

    /**
     * Polling interval (microseconds) when waiting for detached process output.
     */
    private const DETACHED_POLL_INTERVAL_US = 100_000;

    /**
     * System environment variables required for headful Chromium.
     *
     * @var list<string>
     */
    private const DISPLAY_VARS = [
        'DISPLAY',
        'WAYLAND_DISPLAY',
        'XDG_RUNTIME_DIR',
    ];

    /**
     * Execute a browser action via the Playwright runner.
     *
     * In headless mode, runs synchronously via Symfony Process (stdin/stdout).
     * In headful mode, launches a detached background process so PHP returns
     * immediately while the browser window stays open for the user to inspect.
     *
     * @param  string  $action  The browser action to perform
     * @param  array<string, mixed>  $arguments  Action-specific arguments
     * @return array{ok: bool, action: string, ...} Parsed JSON result
     *
     * @throws RuntimeException If the process fails, times out, or returns invalid output
     */
    public function execute(string $action, array $arguments = []): array
    {
        $scriptPath = $this->scriptPath();

        if (! file_exists($scriptPath)) {
            throw new RuntimeException(
                'Browser runner script not found at: '.$scriptPath
            );
        }

        // Per-call headless override takes priority over config.
        // The 'headless' key is consumed here and removed from $arguments
        // so it doesn't leak into the runner's action-specific input.
        if (array_key_exists('headless', $arguments)) {
            $headless = (bool) $arguments['headless'];
            unset($arguments['headless']);
        } else {
            $headless = (bool) config('ai.tools.browser.headless', true);
        }

        $displayEnv = [];

        if (! $headless) {
            $displayEnv = $this->resolveDisplayEnvironment();

            if (! isset($displayEnv['DISPLAY'])) {
                // No display server available — fall back to headless silently.
                // The runner script will include a fallback notice in the result.
                $headless = true;
            }
        }

        // In headful mode, keep the browser window open after action so
        // the user can inspect the page. The runner writes its result and
        // then enters a keep-alive loop until the user closes the window
        // or the timeout expires.
        $keepOpen = ! $headless;

        $input = json_encode([
            'action' => $action,
            'headless' => $headless,
            'keepOpen' => $keepOpen,
            'executablePath' => config('ai.tools.browser.executable_path'),
            ...$arguments,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        // Headful + keepOpen: launch as detached background process so PHP
        // can return the result without waiting for the browser to close.
        if ($keepOpen) {
            return $this->executeDetached($input, $scriptPath, $displayEnv);
        }

        // Headless: synchronous execution via Symfony Process.
        $process = new Process(['node', $scriptPath]);
        $process->setInput($input);
        $process->setTimeout(self::PROCESS_TIMEOUT);

        // Explicitly set display vars so Chromium can find the X/Wayland
        // server. Symfony Process inherits the parent env, but the PHP
        // process (web server, queue worker) may not carry display vars.
        if ($displayEnv !== []) {
            $process->setEnv($displayEnv);
        }

        $process->run();

        if (! $process->isSuccessful()) {
            $stderr = trim($process->getErrorOutput());
            $stdout = trim($process->getOutput());

            // Try to parse stdout for structured error from the runner
            if ($stdout !== '') {
                $parsed = json_decode($stdout, true);
                if (is_array($parsed) && isset($parsed['ok'])) {
                    return $parsed;
                }
            }

            throw new RuntimeException(
                'Browser process failed (exit '.$process->getExitCode().'): '
                .($stderr !== '' ? $stderr : 'No error output')
            );
        }

        $output = trim($process->getOutput());

        if ($output === '') {
            throw new RuntimeException('Browser runner returned empty output.');
        }

        $result = json_decode($output, true);

        if (! is_array($result) || ! isset($result['ok'])) {
            throw new RuntimeException(
                'Browser runner returned invalid JSON: '.substr($output, 0, 200)
            );
        }

        return $result;
    }

    /**
     * Execute the runner as a detached background process.
     *
     * Used for headful mode: PHP launches the node process with setsid
     * (new session), communicates via temp files (input file -> node,
     * node -> output file via atomic rename), and polls for the result.
     *
     * The node process continues running after writing the result,
     * keeping the browser window open until the user closes it or the
     * keep-alive timeout expires (default 5 minutes).
     *
     * @param  string  $input  JSON-encoded input for the runner
     * @param  string  $scriptPath  Absolute path to browser-runner.mjs
     * @param  array<string, string>  $displayEnv  Display environment variables
     * @return array{ok: bool, action: string, ...} Parsed JSON result
     *
     * @throws RuntimeException If the process fails to start, times out, or returns invalid output
     */
    private function executeDetached(string $input, string $scriptPath, array $displayEnv): array
    {
        $inputFile = tempnam(sys_get_temp_dir(), 'blb_browser_');
        $outputFile = $inputFile.'.out';

        file_put_contents($inputFile, $input);

        // Build shell environment prefix. Keys are safe constants —
        // only values need escaping.
        $envParts = [];
        foreach ($displayEnv as $key => $value) {
            $envParts[] = $key.'='.escapeshellarg($value);
        }
        $envParts[] = 'BLB_INPUT_FILE='.escapeshellarg($inputFile);
        $envParts[] = 'BLB_OUTPUT_FILE='.escapeshellarg($outputFile);

        $cmd = sprintf(
            '%s setsid node %s </dev/null >/dev/null 2>&1 &',
            implode(' ', $envParts),
            escapeshellarg($scriptPath),
        );

        exec($cmd);

        // Poll for the output file. The runner writes to a .tmp file first,
        // then atomically renames it, so we only ever see complete JSON.
        $deadline = microtime(true) + self::PROCESS_TIMEOUT;

        while (microtime(true) < $deadline) {
            clearstatcache(true, $outputFile);

            if (file_exists($outputFile)) {
                $content = file_get_contents($outputFile);

                if ($content !== false && $content !== '') {
                    @unlink($outputFile);

                    $result = json_decode($content, true);

                    if (! is_array($result) || ! isset($result['ok'])) {
                        throw new RuntimeException(
                            'Browser runner returned invalid JSON: '.substr($content, 0, 200)
                        );
                    }

                    return $result;
                }
            }

            usleep(self::DETACHED_POLL_INTERVAL_US);
        }

        // Timeout — clean up temp files
        @unlink($inputFile);
        @unlink($outputFile);

        throw new RuntimeException('Browser runner timed out waiting for result.');
    }

    /**
     * Check whether the runner infrastructure is available.
     *
     * Verifies the Node.js script exists. Does not verify Node.js itself
     * is installed — that's caught at execution time with a clear error.
     */
    public function isAvailable(): bool
    {
        return file_exists($this->scriptPath());
    }

    /**
     * Resolve display server environment variables for headful mode.
     *
     * Collects DISPLAY, WAYLAND_DISPLAY, and XDG_RUNTIME_DIR from the
     * system environment. If DISPLAY is not set, attempts to detect an
     * available X11 socket from /tmp/.X11-unix/.
     *
     * @return array<string, string> Display-related env vars (may be empty)
     */
    private function resolveDisplayEnvironment(): array
    {
        $env = [];

        foreach (self::DISPLAY_VARS as $var) {
            $value = getenv($var);
            if ($value !== false) {
                $env[$var] = $value;
            }
        }

        // If DISPLAY not in PHP env, detect from X11 socket.
        // This handles cases where the web server / queue worker was
        // started without inheriting display vars from the desktop session.
        if (! isset($env['DISPLAY'])) {
            $sockets = glob('/tmp/.X11-unix/X*');
            if ($sockets !== false && $sockets !== []) {
                $displayNumber = ltrim(basename(end($sockets)), 'X');
                $env['DISPLAY'] = ':'.$displayNumber;
            }
        }

        return $env;
    }

    /**
     * Path to the browser runner Node.js script.
     */
    private function scriptPath(): string
    {
        return resource_path('core/scripts/browser-runner.mjs');
    }
}
