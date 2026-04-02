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
use App\Modules\Core\AI\Enums\BrowserArtifactType;
use App\Modules\Core\AI\Services\Browser\BrowserArtifactStore;
use App\Modules\Core\AI\Services\Browser\BrowserSessionException;
use App\Modules\Core\AI\Services\Browser\BrowserSessionManager;
use App\Modules\Core\AI\Services\Browser\BrowserSsrfGuard;
use RuntimeException;

/**
 * Browser automation tool for Agents — thin wrapper over browser subsystem.
 *
 * Provides browser automation via Chromium/Playwright. Supports navigation,
 * page snapshots, screenshots, interaction, tab management, JS evaluation
 * (opt-in), PDF export, cookie management, and wait conditions.
 *
 * All lifecycle, session state, and artifact persistence are delegated to
 * the browser subsystem services (BrowserSessionManager, BrowserArtifactStore).
 * This tool is an action router: it validates arguments, enforces SSRF and
 * evaluate policy gates, then dispatches to the session manager.
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
     * Active session ID for the current tool lifecycle.
     *
     * Set during handleAction() from resolved session context and
     * used by action handlers to route through the session manager.
     */
    private ?string $activeSessionId = null;

    public function __construct(
        private readonly BrowserSessionManager $sessionManager,
        private readonly BrowserSsrfGuard $ssrfGuard,
        private readonly BrowserArtifactStore $artifactStore,
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
     * Overrides parent to check browser availability and resolve a session
     * before dispatch. Creates or reuses a persistent browser session
     * scoped to the current agent and company.
     *
     * @param  string  $action  The validated action name
     * @param  array<string, mixed>  $arguments  Full arguments (including 'action')
     *
     * @throws ToolUnavailableException If browser automation is not available
     */
    protected function handleAction(string $action, array $arguments): ToolResult
    {
        if (! $this->sessionManager->isAvailable()) {
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

        // Resolve or create a browser session for this tool invocation.
        // The execution context should eventually populate these synthetic fields.
        $headless = array_key_exists('headless', $arguments)
            ? (bool) $arguments['headless']
            : (bool) config('ai.tools.browser.headless', true);

        try {
            $session = $this->sessionManager->open(
                employeeId: $arguments['_employee_id'] ?? 0,
                companyId: $arguments['_company_id'] ?? 0,
                headless: $headless,
            );
            $this->activeSessionId = $session->id;
        } catch (BrowserSessionException $e) {
            return ToolResult::error($e->getMessage(), 'browser_session_error');
        }

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
            $this->activeSessionId = null;
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
     * session manager to open a new tab in the active browser session.
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
     * Validates interaction parameters in PHP, then delegates to the
     * session manager. Operates against the browser session's current
     * page state and known element refs.
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
     * Lists all open browser tabs in the current session.
     */
    private function handleTabs(): ToolResult
    {
        return $this->executeRunner('tabs');
    }

    /**
     * Handle the "close" action.
     *
     * Closes a browser tab by tab ID within the current session.
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
     * Execute a browser action via the session manager and convert the result.
     *
     * Routes through the persistent browser session. On screenshot/snapshot/pdf
     * success, stores the output as a durable artifact.
     *
     * @param  string  $action  The browser action name
     * @param  array<string, mixed>  $arguments  Action-specific arguments (without 'action')
     */
    private function executeRunner(string $action, array $arguments = []): ToolResult
    {
        try {
            $result = $this->sessionManager->executeAction(
                $this->activeSessionId,
                $action,
                $arguments,
            );
        } catch (BrowserSessionException $e) {
            return ToolResult::error($e->getMessage(), 'browser_session_error');
        } catch (RuntimeException $e) {
            return ToolResult::error(
                'Browser action failed: '.$e->getMessage(),
                'browser_process_error',
            );
        }

        // Store durable artifacts for output-producing actions.
        if ($result['ok'] ?? false) {
            $this->maybeStoreArtifact($action, $result);
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

    /**
     * Store artifact for output-producing actions (screenshot, snapshot, pdf, evaluate).
     *
     * @param  array<string, mixed>  $result
     */
    private function maybeStoreArtifact(string $action, array $result): void
    {
        $type = match ($action) {
            'screenshot' => BrowserArtifactType::Screenshot,
            'snapshot' => BrowserArtifactType::Snapshot,
            'pdf' => BrowserArtifactType::Pdf,
            'evaluate' => BrowserArtifactType::EvaluateResult,
            default => null,
        };

        if ($type === null || $this->activeSessionId === null) {
            return;
        }

        $content = match ($action) {
            'screenshot' => base64_decode($result['data'] ?? '', true) ?: null,
            'pdf' => base64_decode($result['data'] ?? '', true) ?: null,
            'snapshot' => $result['content'] ?? $result['snapshot'] ?? null,
            'evaluate' => isset($result['result']) ? json_encode($result['result'], JSON_UNESCAPED_SLASHES) : null,
            default => null,
        };

        if ($content === null || $content === '' || $content === false) {
            return;
        }

        $this->artifactStore->store(
            sessionId: $this->activeSessionId,
            type: $type,
            content: $content,
            relatedUrl: $result['url'] ?? null,
        );
    }
}
