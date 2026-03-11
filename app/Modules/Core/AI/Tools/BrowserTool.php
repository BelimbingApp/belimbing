<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\AI\Tools;

use App\Base\AI\Enums\ToolCategory;
use App\Base\AI\Enums\ToolRiskClass;
use App\Base\AI\Tools\AbstractActionTool;
use App\Base\AI\Tools\Schema\ToolSchemaBuilder;
use App\Base\AI\Tools\SetupAction;
use App\Base\AI\Tools\ToolArgumentException;
use App\Base\AI\Tools\ToolResult;
use App\Base\AI\Tools\ToolUnavailableException;
use App\Modules\Core\AI\Services\Browser\BrowserPoolManager;
use App\Modules\Core\AI\Services\Browser\BrowserSsrfGuard;

/**
 * Headless browser automation tool for Digital Workers.
 *
 * Provides enterprise-grade browser automation via server-side headless
 * Chromium managed through a pool of isolated browser contexts. Supports
 * navigation, page snapshots, screenshots, interaction, tab management,
 * JS evaluation (opt-in), PDF export, cookie management, and wait conditions.
 *
 * Each action is dispatched through a single deep tool interface. Browser
 * contexts are company-scoped and session-isolated via BrowserPoolManager.
 * All navigation is SSRF-guarded.
 *
 * Note: Currently returns stub responses. Full Playwright CLI subprocess
 * integration will be implemented once the browser infrastructure is deployed.
 *
 * Gated by `ai.tool_browser.execute` authz capability.
 * The `evaluate` action additionally requires `ai.tool_browser_evaluate.execute`.
 */
class BrowserTool extends AbstractActionTool
{
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

    public function __construct(
        private readonly BrowserPoolManager $poolManager,
        private readonly BrowserSsrfGuard $ssrfGuard,
    ) {}

    public function name(): string
    {
        return 'browser';
    }

    public function description(): string
    {
        return 'Automate a headless browser for web scraping, form filling, and page inspection. '
            .'Supports navigation, page snapshots (structured text), screenshots, interaction '
            .'(click, type, select, fill), tab management, PDF export, cookie management, '
            .'and waiting for page state. Each DW session gets an isolated browser context.';
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

        return match ($action) {
            'navigate' => $this->handleNavigation('navigate', 'navigated', $arguments),
            'snapshot' => $this->handleSnapshot($arguments),
            'screenshot' => $this->handleScreenshot($arguments),
            'act' => $this->handleAct($arguments),
            'tabs' => $this->handleTabs(),
            'open' => $this->handleNavigation('open', 'opened', $arguments),
            'close' => $this->handleClose($arguments),
            'evaluate' => $this->handleEvaluate($arguments),
            'pdf' => $this->handlePdf(),
            'cookies' => $this->handleCookies($arguments),
            'wait' => $this->handleWait($arguments),
        };
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    private function handleNavigation(string $action, string $status, array $arguments): ToolResult
    {
        $url = $this->requireString($arguments, 'url');
        $ssrfCheck = $this->ssrfGuard->validate($url);

        if ($ssrfCheck !== true) {
            return ToolResult::error($ssrfCheck, 'ssrf_blocked');
        }

        $payload = [
            'action' => $action,
            'url' => $url,
            'status' => $status,
            'message' => $action === 'navigate'
                ? 'Navigation completed (stub). Playwright integration pending.'
                : 'New tab opened (stub). Playwright integration pending.',
        ];

        if ($action === 'navigate') {
            $payload['title'] = '';
        }

        if ($action === 'open') {
            $payload['tab_id'] = '';
        }

        return ToolResult::success(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    /**
     * Handle the "snapshot" action.
     *
     * Returns a structured text representation of the page for LLM consumption.
     *
     * @param  array<string, mixed>  $arguments  Parsed arguments from LLM
     */
    private function handleSnapshot(array $arguments): ToolResult
    {
        $format = $this->requireEnum($arguments, 'format', ['ai', 'aria'], 'ai');

        return ToolResult::success(json_encode([
            'action' => 'snapshot',
            'format' => $format,
            'interactive' => $this->optionalBool($arguments, 'interactive', true),
            'compact' => $this->optionalBool($arguments, 'compact'),
            'content' => '',
            'status' => 'captured',
            'message' => 'Snapshot captured (stub). Playwright integration pending.',
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
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
        return ToolResult::success(json_encode([
            'action' => 'screenshot',
            'full_page' => $this->optionalBool($arguments, 'full_page'),
            'ref' => $this->optionalString($arguments, 'ref'),
            'selector' => $this->optionalString($arguments, 'selector'),
            'image_base64' => '',
            'status' => 'captured',
            'message' => 'Screenshot captured (stub). Playwright integration pending.',
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    /**
     * Handle the "act" action.
     *
     * Performs an interaction on a page element using snapshot refs.
     *
     * @param  array<string, mixed>  $arguments  Parsed arguments from LLM
     */
    private function handleAct(array $arguments): ToolResult
    {
        return ToolResult::success(json_encode([
            'action' => 'act',
            'kind' => $this->requireEnum($arguments, 'kind', self::ACT_KINDS),
            'ref' => $this->requireString($arguments, 'ref'),
            'text' => $this->optionalString($arguments, 'text'),
            'submit' => $this->optionalBool($arguments, 'submit'),
            'status' => 'performed',
            'message' => 'Action performed (stub). Playwright integration pending.',
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    /**
     * Handle the "tabs" action.
     *
     * Lists all open browser tabs.
     */
    private function handleTabs(): ToolResult
    {
        return ToolResult::success(json_encode([
            'action' => 'tabs',
            'tabs' => [],
            'status' => 'listed',
            'message' => 'Tabs listed (stub). Playwright integration pending.',
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    /**
     * Handle the "close" action.
     *
     * Closes a browser tab by tab ID.
     *
     * @param  array<string, mixed>  $arguments  Parsed arguments from LLM
     */
    private function handleClose(array $arguments): ToolResult
    {
        return ToolResult::success(json_encode([
            'action' => 'close',
            'tab_id' => $this->requireString($arguments, 'tab_id'),
            'status' => 'closed',
            'message' => 'Tab closed (stub). Playwright integration pending.',
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
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

        return ToolResult::success(json_encode([
            'action' => 'evaluate',
            'script' => $this->requireString($arguments, 'script'),
            'result' => null,
            'status' => 'evaluated',
            'message' => 'Script evaluated (stub). Playwright integration pending.',
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    /**
     * Handle the "pdf" action.
     *
     * Exports the current page as a PDF document.
     */
    private function handlePdf(): ToolResult
    {
        return ToolResult::success(json_encode([
            'action' => 'pdf',
            'path' => '',
            'status' => 'exported',
            'message' => 'PDF exported (stub). Playwright integration pending.',
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    /**
     * Handle the "cookies" action.
     *
     * Manages cookies for the current browser context.
     *
     * @param  array<string, mixed>  $arguments  Parsed arguments from LLM
     */
    private function handleCookies(array $arguments): ToolResult
    {
        $cookieAction = $this->requireEnum($arguments, 'cookie_action', self::COOKIE_ACTIONS);
        $payload = [
            'action' => 'cookies',
            'cookie_action' => $cookieAction,
            'cookie_name' => $this->optionalString($arguments, 'cookie_name'),
        ];

        if ($cookieAction === 'get') {
            $payload['cookies'] = [];
            $payload['status'] = 'retrieved';
            $payload['message'] = 'Cookies retrieved (stub). Playwright integration pending.';
        }

        if ($cookieAction === 'set') {
            $cookieValue = $arguments['cookie_value'] ?? '';

            if (! is_string($cookieValue)) {
                throw new ToolArgumentException('"cookie_value" is required to set a cookie.');
            }

            $payload['cookie_name'] = $this->requireString($arguments, 'cookie_name');
            $payload['cookie_value'] = $cookieValue;
            $payload['cookie_url'] = $this->optionalString($arguments, 'cookie_url');
            $payload['status'] = 'set';
            $payload['message'] = 'Cookie set (stub). Playwright integration pending.';
        }

        if ($cookieAction === 'clear') {
            $payload['status'] = 'cleared';
            $payload['message'] = 'Cookies cleared (stub). Playwright integration pending.';
        }

        return ToolResult::success(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
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

        return ToolResult::success(json_encode([
            'action' => 'wait',
            'text' => $text,
            'selector' => $selector,
            'url' => $url,
            'timeout_ms' => $this->optionalInt($arguments, 'timeout_ms', 5000, 100),
            'status' => 'waited',
            'message' => 'Wait completed (stub). Playwright integration pending.',
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }
}
