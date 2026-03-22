<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\AI\Tools;

use App\Base\AI\Enums\ToolCategory;
use App\Base\AI\Enums\ToolRiskClass;
use App\Base\AI\Tools\AbstractActionTool;
use App\Base\AI\Tools\Concerns\ProvidesToolMetadata;
use App\Base\AI\Tools\Schema\ToolSchemaBuilder;
use App\Base\AI\Tools\SetupAction;
use App\Base\AI\Tools\ToolArgumentException;
use App\Base\AI\Tools\ToolResult;
use App\Base\AI\Tools\ToolUnavailableException;
use App\Modules\Core\AI\Services\Browser\BrowserPoolManager;
use App\Modules\Core\AI\Services\Browser\BrowserSsrfGuard;
use App\Modules\Core\AI\Services\Browser\PlaywrightRunner;
use RuntimeException;

/**
 * Browser automation tool for Agents — headful or headless.
 *
 * Provides enterprise-grade browser automation via Chromium driven by
 * a Playwright Node.js subprocess. Supports navigation, page snapshots,
 * screenshots, interaction, tab management, JS evaluation (opt-in), PDF
 * export, cookie management, and wait conditions.
 *
 * Runs in headful mode (visible browser window) in local environments and
 * headless mode (no GUI) in production/staging. The mode is determined by
 * APP_ENV and can be overridden via AI_BROWSER_HEADLESS.
 *
 * Each action is dispatched through a single deep tool interface. PHP
 * handles argument validation, SSRF guarding, and config gating; the
 * actual browser work is delegated to PlaywrightRunner which launches
 * a per-command Chromium process.
 *
 * Session-dependent actions (act, tabs, open, close) require a persistent
 * browser process and are not yet supported — the runner returns a clear
 * session_required error for these.
 *
 * Gated by `ai.tool_browser.execute` authz capability.
 * The `evaluate` action additionally requires `ai.tool_browser_evaluate.execute`.
 */
class BrowserTool extends AbstractActionTool
{
    use ProvidesToolMetadata;

    /**
     * Valid actions for browser automation.
     *
     * @var list<string>
     */
    private const ACTIONS = [
        'navigate',
        'snapshot',
        'screenshot',
        'act',
        'tabs',
        'open',
        'close',
        'evaluate',
        'pdf',
        'cookies',
        'wait',
    ];

    /**
     * Valid interaction kinds for the "act" action.
     *
     * @var list<string>
     */
    private const ACT_KINDS = [
        'click',
        'type',
        'select',
        'press',
        'drag',
        'hover',
        'scroll',
        'fill',
    ];

    /**
     * Valid cookie sub-actions.
     *
     * @var list<string>
     */
    private const COOKIE_ACTIONS = [
        'get',
        'set',
        'clear',
    ];

    /**
     * Per-call headless override, set by handleAction() from the input
     * arguments and injected into executeRunner() calls. Null means
     * "use global config" (no override). Reset after each action dispatch.
     */
    private ?bool $headlessOverride = null;

    public function __construct(
        private readonly BrowserPoolManager $poolManager,
        private readonly BrowserSsrfGuard $ssrfGuard,
        private readonly PlaywrightRunner $runner,
    ) {}

    public function name(): string
    {
        return 'browser';
    }

    public function description(): string
    {
        return 'Automate a browser for web scraping, form filling, and page inspection. '
            .'Runs headful (visible window) in local environments or headless (no GUI) in production. '
            .'Supports navigation, page snapshots (structured text), screenshots, interaction '
            .'(click, type, select, fill), tab management, PDF export, cookie management, '
            .'and waiting for page state. Each agent session gets an isolated browser context.';
    }

    public function category(): ToolCategory
    {
        return ToolCategory::BROWSER;
    }

    public function riskClass(): ToolRiskClass
    {
        return ToolRiskClass::BROWSER;
    }

    public function requiredCapability(): ?string
    {
        return 'ai.tool_browser.execute';
    }

    protected function toolMetadata(): array
    {
        return [
            'displayName' => 'Browser',
            'summary' => 'Automate browser actions for web scraping, RPA, and human-AI collaboration.',
            'explanation' => 'Chromium-based browser automation with two operating modes. '
                .'<strong>Headful</strong> mode opens a visible browser window — the human can watch the AI '
                .'navigate, click, and fill forms in real time, enabling collaborative workflows '
                .'where the AI drives and the human supervises or intervenes. '
                .'<strong>Headless</strong> mode runs without a GUI, optimized for server-side scraping and RPA '
                .'where no visual feedback is needed. '
                .'The mode is determined automatically by environment: '
                .'<code>APP_ENV=local</code> defaults to headful for development and collaboration; '
                .'production and staging default to headless. '
                .'Set <code>AI_BROWSER_HEADLESS=true</code> or <code>false</code> to override.',
            'setupRequirements' => [
                'Chromium browser available',
                'Browser pool available',
            ],
            'testExamples' => [
                [
                    'label' => 'Headful — Navigate to URL',
                    'input' => ['action' => 'navigate', 'url' => 'https://example.com'],
                ],
                [
                    'label' => 'Headless — Snapshot',
                    'input' => ['action' => 'snapshot', 'headless' => true],
                ],
            ],
            'healthChecks' => [
                'Browser pool available',
                'Chromium process responsive',
            ],
            'limits' => [
                'Company-scoped browser contexts',
                'Session isolation between agents',
            ],
        ];
    }

    /**
     * @return list<string>
     */
    protected function actions(): array
    {
        return self::ACTIONS;
    }

    protected function schema(): ToolSchemaBuilder
    {
        return ToolSchemaBuilder::make()
            ->string('url', 'URL to navigate to (for "navigate" and "open" actions).')
            ->string('format', 'Snapshot format: "ai" for LLM-optimized (default), "aria" for accessibility tree.', ['ai', 'aria'])
            ->boolean('interactive', 'Whether to include interactive element refs in snapshot (default true).')
            ->boolean('compact', 'Whether to return a compact snapshot (default false).')
            ->boolean('full_page', 'Capture full page screenshot instead of viewport only.')
            ->string('ref', 'Element reference from a snapshot, used by "act" and "screenshot" actions.')
            ->string('selector', 'CSS selector for targeting elements (screenshot, wait).')
            ->string('kind', 'Interaction kind for "act" action: click, type, select, press, drag, hover, scroll, fill.', self::ACT_KINDS)
            ->string('text', 'Text input for type/fill/press actions, or text to wait for.')
            ->boolean('submit', 'Whether to submit the form after typing/filling (default false).')
            ->string('tab_id', 'Tab identifier for "close" action.')
            ->string('script', 'JavaScript code to evaluate in page context (requires evaluate to be enabled).')
            ->string('cookie_action', 'Cookie sub-action: "get", "set", or "clear".', self::COOKIE_ACTIONS)
            ->string('cookie_name', 'Cookie name (for get/set/clear).')
            ->string('cookie_value', 'Cookie value (for set).')
            ->string('cookie_url', 'URL scope for cookie operations.')
            ->integer('timeout_ms', 'Timeout in milliseconds for "wait" action (default 5000).');
    }

    /**
     * Dispatch to the appropriate browser action handler.
     *
     * Overrides parent to check browser availability before dispatch.
     * Throws ToolUnavailableException with a Lara handoff action if
     * Playwright is not installed or browser automation is disabled.
     *
     * @param  string  $action  The validated action name
     * @param  array<string, mixed>  $arguments  Full arguments (including 'action')
     *
     * @throws ToolUnavailableException If browser automation is not available
     */
    protected function handleAction(string $action, array $arguments): ToolResult
    {
        if (! $this->poolManager->isAvailable()) {
            throw new ToolUnavailableException(
                errorCode: 'browser_unavailable',
                message: 'Browser automation is not available. '
                    .'The browser tool is either disabled or Playwright is not installed.',
                hint: 'An administrator needs to install Playwright and enable the browser tool.',
                action: new SetupAction(
                    label: __('Ask Lara to set up browser'),
                    suggestedPrompt: 'Help me set up the browser tool. Playwright may not be installed '
                        .'or the browser tool may be disabled in the configuration. '
                        .'Please diagnose and fix the issue.',
                ),
            );
        }

        // Capture per-call headless override from input arguments.
        // This is consumed by executeRunner() and cleared after dispatch
        // so it doesn't bleed into subsequent calls on the same instance.
        $this->headlessOverride = array_key_exists('headless', $arguments)
            ? (bool) $arguments['headless']
            : null;

        try {
            return match ($action) {
                'navigate' => $this->handleNavigate($arguments),
                'snapshot' => $this->handleSnapshot($arguments),
                'screenshot' => $this->handleScreenshot($arguments),
                'act' => $this->handleAct($arguments),
                'tabs' => $this->handleTabs(),
                'open' => $this->handleOpen($arguments),
                'close' => $this->handleClose($arguments),
                'evaluate' => $this->handleEvaluate($arguments),
                'pdf' => $this->handlePdf($arguments),
                'cookies' => $this->handleCookies($arguments),
                'wait' => $this->handleWait($arguments),
            };
        } finally {
            $this->headlessOverride = null;
        }
    }

    /**
     * Handle the "navigate" action.
     *
     * Validates the URL against the SSRF guard, then delegates to the
     * Playwright runner to perform actual navigation.
     *
     * @param  array<string, mixed>  $arguments
     */
    private function handleNavigate(array $arguments): ToolResult
    {
        $url = $this->requireString($arguments, 'url');
        $ssrfCheck = $this->ssrfGuard->validate($url);

        if ($ssrfCheck !== true) {
            return ToolResult::error($ssrfCheck, 'ssrf_blocked');
        }

        return $this->executeRunner('navigate', ['url' => $url]);
    }

    /**
     * Handle the "open" action (new tab).
     *
     * Validates the URL against the SSRF guard, then delegates to the
     * Playwright runner. Currently returns session_required since tab
     * management needs a persistent browser process.
     *
     * @param  array<string, mixed>  $arguments
     */
    private function handleOpen(array $arguments): ToolResult
    {
        $url = $this->requireString($arguments, 'url');
        $ssrfCheck = $this->ssrfGuard->validate($url);

        if ($ssrfCheck !== true) {
            return ToolResult::error($ssrfCheck, 'ssrf_blocked');
        }

        return $this->executeRunner('open', ['url' => $url]);
    }

    /**
     * Handle the "snapshot" action.
     *
     * Returns a structured text representation of the page for LLM consumption.
     * Delegates to the Playwright runner for actual page content extraction.
     *
     * @param  array<string, mixed>  $arguments  Parsed arguments from LLM
     */
    private function handleSnapshot(array $arguments): ToolResult
    {
        $format = $this->requireEnum($arguments, 'format', ['ai', 'aria'], 'ai');

        return $this->executeRunner('snapshot', [
            'format' => $format,
            'interactive' => $this->optionalBool($arguments, 'interactive', true),
            'compact' => $this->optionalBool($arguments, 'compact'),
        ]);
    }

    /**
     * Handle the "screenshot" action.
     *
     * Captures a screenshot of the viewport or a specific element.
     *
     * @param  array<string, mixed>  $arguments  Parsed arguments from LLM
     */
    private function handleScreenshot(array $arguments): ToolResult
    {
        return $this->executeRunner('screenshot', [
            'full_page' => $this->optionalBool($arguments, 'full_page'),
            'ref' => $this->optionalString($arguments, 'ref'),
            'selector' => $this->optionalString($arguments, 'selector'),
        ]);
    }

    /**
     * Handle the "act" action.
     *
     * Validates interaction parameters in PHP, then delegates to the runner.
     * Currently returns session_required since element interaction needs
     * a persistent browser session with prior page state.
     *
     * @param  array<string, mixed>  $arguments  Parsed arguments from LLM
     */
    private function handleAct(array $arguments): ToolResult
    {
        $kind = $this->requireEnum($arguments, 'kind', self::ACT_KINDS);
        $ref = $this->requireString($arguments, 'ref');

        return $this->executeRunner('act', [
            'kind' => $kind,
            'ref' => $ref,
            'text' => $this->optionalString($arguments, 'text'),
            'submit' => $this->optionalBool($arguments, 'submit'),
        ]);
    }

    /**
     * Handle the "tabs" action.
     *
     * Lists all open browser tabs. Currently returns session_required
     * since tab management needs a persistent browser process.
     */
    private function handleTabs(): ToolResult
    {
        return $this->executeRunner('tabs');
    }

    /**
     * Handle the "close" action.
     *
     * Closes a browser tab by tab ID. Currently returns session_required
     * since tab management needs a persistent browser process.
     *
     * @param  array<string, mixed>  $arguments  Parsed arguments from LLM
     */
    private function handleClose(array $arguments): ToolResult
    {
        $tabId = $this->requireString($arguments, 'tab_id');

        return $this->executeRunner('close', ['tab_id' => $tabId]);
    }

    /**
     * Handle the "evaluate" action.
     *
     * Executes JavaScript in the page context. Disabled by default;
     * requires config('ai.tools.browser.evaluate_enabled') to be true.
     * This is a high-trust action with a separate authz capability.
     *
     * @param  array<string, mixed>  $arguments  Parsed arguments from LLM
     *
     * @throws ToolUnavailableException If JS evaluation is disabled
     */
    private function handleEvaluate(array $arguments): ToolResult
    {
        if (! config('ai.tools.browser.evaluate_enabled', false)) {
            throw new ToolUnavailableException(
                errorCode: 'browser_evaluate_disabled',
                message: 'JavaScript evaluation is disabled.',
                hint: 'An administrator must enable it via config("ai.tools.browser.evaluate_enabled").',
                action: new SetupAction(
                    label: __('Ask Lara to enable JS evaluation'),
                    suggestedPrompt: 'Help me enable JavaScript evaluation in the browser tool. '
                        .'The config key ai.tools.browser.evaluate_enabled needs to be set to true.',
                ),
            );
        }

        $script = $this->requireString($arguments, 'script');

        return $this->executeRunner('evaluate', ['script' => $script]);
    }

    /**
     * Handle the "pdf" action.
     *
     * Exports the current page as a PDF document.
     *
     * @param  array<string, mixed>  $arguments  Parsed arguments from LLM
     */
    private function handlePdf(array $arguments): ToolResult
    {
        return $this->executeRunner('pdf', [
            'url' => $this->optionalString($arguments, 'url'),
        ]);
    }

    /**
     * Handle the "cookies" action.
     *
     * Validates cookie parameters in PHP, then delegates to the runner.
     *
     * @param  array<string, mixed>  $arguments  Parsed arguments from LLM
     */
    private function handleCookies(array $arguments): ToolResult
    {
        $cookieAction = $this->requireEnum($arguments, 'cookie_action', self::COOKIE_ACTIONS);
        $runnerArgs = ['cookie_action' => $cookieAction];

        if ($cookieAction === 'set') {
            $cookieValue = $arguments['cookie_value'] ?? '';

            if (! is_string($cookieValue)) {
                throw new ToolArgumentException('"cookie_value" is required to set a cookie.');
            }

            $runnerArgs['cookie_name'] = $this->requireString($arguments, 'cookie_name');
            $runnerArgs['cookie_value'] = $cookieValue;
            $runnerArgs['cookie_url'] = $this->optionalString($arguments, 'cookie_url');
        } else {
            $runnerArgs['cookie_name'] = $this->optionalString($arguments, 'cookie_name');
        }

        return $this->executeRunner('cookies', $runnerArgs);
    }

    /**
     * Handle the "wait" action.
     *
     * Waits for a specific page state: text content, CSS selector, or URL match.
     *
     * @param  array<string, mixed>  $arguments  Parsed arguments from LLM
     */
    private function handleWait(array $arguments): ToolResult
    {
        $text = $this->optionalString($arguments, 'text');
        $selector = $this->optionalString($arguments, 'selector');
        $url = $this->optionalString($arguments, 'url');

        if ($text === null && $selector === null && $url === null) {
            throw new ToolArgumentException(
                'At least one of "text", "selector", or "url" is required for the wait action.'
            );
        }

        return $this->executeRunner('wait', [
            'text' => $text,
            'selector' => $selector,
            'url' => $url,
            'timeout_ms' => $this->optionalInt($arguments, 'timeout_ms', 5000, 100),
        ]);
    }

    // ─── Runner integration ─────────────────────────────────────────

    /**
     * Execute a browser action via the Playwright runner and convert the result.
     *
     * Catches RuntimeException from the runner (process failures, timeouts,
     * invalid output) and converts them to ToolResult errors.
     *
     * @param  string  $action  The browser action name
     * @param  array<string, mixed>  $arguments  Action-specific arguments (without 'action')
     */
    private function executeRunner(string $action, array $arguments = []): ToolResult
    {
        // Forward per-call headless override to the runner, which will
        // use it instead of the global config value.
        if ($this->headlessOverride !== null) {
            $arguments['headless'] = $this->headlessOverride;
        }

        try {
            $result = $this->runner->execute($action, $arguments);
        } catch (RuntimeException $e) {
            return ToolResult::error(
                'Browser action failed: '.$e->getMessage(),
                'browser_process_error',
            );
        }

        return $this->runnerResultToToolResult($result);
    }

    /**
     * Convert a runner result array to a ToolResult.
     *
     * The runner returns {ok: bool, action: string, ...}. On success, the
     * full result is JSON-encoded for the LLM. On failure, the error message
     * is extracted and returned as a ToolResult error.
     *
     * @param  array{ok: bool, action: string, error?: string, message?: string}  $result
     */
    private function runnerResultToToolResult(array $result): ToolResult
    {
        if ($result['ok']) {
            return ToolResult::success(
                json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
            );
        }

        $errorCode = $result['error'] ?? 'browser_error';
        $message = $result['message'] ?? 'Browser action failed.';

        return ToolResult::error($message, $errorCode);
    }
}
