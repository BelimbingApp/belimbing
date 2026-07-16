<?php

namespace App\Modules\Core\AI\Tools;

use App\Base\AI\Tools\Schema\ToolSchemaBuilder;
use App\Base\AI\Tools\ToolResult;

/**
 * Browser surface for untrusted public-source research.
 *
 * Navigation and inspection are available, while page interaction, cookie
 * mutation, JavaScript evaluation, and form submission are absent from both
 * the advertised schema and the server-side action gate.
 */
final class ReadOnlyBrowserTool extends BrowserTool
{
    /** @var list<string> */
    private const READ_ONLY_ACTIONS = [
        'navigate',
        'snapshot',
        'screenshot',
        'tabs',
        'open',
        'close',
        'wait',
    ];

    public function name(): string
    {
        return 'browser_read_only';
    }

    public function description(): string
    {
        return 'Navigate and inspect public web pages in an isolated browser. '
            .'This tool cannot click, type, fill, submit forms, change cookies, or evaluate JavaScript.';
    }

    protected function toolMetadata(): array
    {
        return [
            'displayName' => 'Read-only browser',
            'summary' => 'Inspect public web pages without interactive or state-changing actions.',
            'explanation' => 'An isolated Chromium session for navigation, snapshots, screenshots, and waits. '
                .'Interactive page actions, cookie mutation, and JavaScript evaluation are excluded in code.',
            'setupRequirements' => [
                'Chromium browser available',
                'Browser pool available',
            ],
            'testExamples' => [[
                'label' => 'Navigate to a public URL',
                'input' => ['action' => 'navigate', 'url' => 'https://example.com'],
            ]],
            'healthChecks' => [
                'Browser pool available',
                'Chromium process responsive',
            ],
            'limits' => [
                'Company-scoped isolated browser contexts',
                'No page interaction, cookie mutation, or JavaScript evaluation',
            ],
        ];
    }

    /** @return list<string> */
    protected function actions(): array
    {
        return self::READ_ONLY_ACTIONS;
    }

    protected function schema(): ToolSchemaBuilder
    {
        return ToolSchemaBuilder::make()
            ->string('url', 'Public URL to navigate to or open in a new tab.')
            ->string('format', 'Snapshot format: "ai" (default) or "aria".', ['ai', 'aria'])
            ->boolean('interactive', 'Include element references in the snapshot.')
            ->boolean('compact', 'Return a compact snapshot.')
            ->boolean('full_page', 'Capture a full-page screenshot.')
            ->string('ref', 'Element reference for a screenshot only.')
            ->string('selector', 'CSS selector for a screenshot or wait condition.')
            ->string('tab_id', 'Tab identifier for the close action.')
            ->string('text', 'Public page text to wait for.')
            ->integer('timeout_ms', 'Wait timeout in milliseconds (default 5000).');
    }

    protected function handleAction(string $action, array $arguments): ToolResult
    {
        if (! in_array($action, self::READ_ONLY_ACTIONS, true)) {
            return ToolResult::error(
                'This browser profile permits navigation and inspection only.',
                'browser_read_only_action_denied',
            );
        }

        return parent::handleAction($action, $arguments);
    }
}
