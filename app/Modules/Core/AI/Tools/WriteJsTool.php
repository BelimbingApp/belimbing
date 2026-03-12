<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\AI\Tools;

use App\Base\AI\Enums\ToolCategory;
use App\Base\AI\Enums\ToolRiskClass;
use App\Base\AI\Tools\AbstractTool;
use App\Base\AI\Tools\Concerns\HasToolMetadata;
use App\Base\AI\Tools\Schema\ToolSchemaBuilder;
use App\Base\AI\Tools\ToolArgumentException;
use App\Base\AI\Tools\ToolResult;

/**
 * Client-side JavaScript execution tool for agents.
 *
 * Allows an agent to execute JavaScript in the user's browser via an
 * `<agent-action>` block that the client-side executor handles.
 * Scripts are validated for safety before execution.
 *
 * Gated by `ai.tool_write_js.execute` authz capability.
 */
class WriteJsTool extends AbstractTool
{
    use HasToolMetadata;

    private const int MAX_SCRIPT_LENGTH = 10000;

    private const int MAX_DESCRIPTION_LENGTH = 500;

    /**
     * Patterns blocked from script content for security.
     *
     * Checked case-insensitively via stripos to prevent code injection,
     * cookie theft, storage access, and dynamic import attacks.
     *
     * @var array<int, string>
     */
    private const array BLOCKED_PATTERNS = [
        'eval(',
        'Function(',
        'document.cookie',
        'localStorage',
        'sessionStorage',
        'importScripts',
        'import(',
    ];

    public function name(): string
    {
        return 'write_js';
    }

    public function description(): string
    {
        return 'Execute client-side JavaScript in the user\'s browser. '
            .'Returns an agent-action block for safe client-side execution. '
            .'Use for dynamic UI updates, clipboard operations, DOM manipulation, or browser API access. '
            .'Scripts must be CSP-compliant.';
    }

    protected function schema(): ToolSchemaBuilder
    {
        return ToolSchemaBuilder::make()
            ->string('script', 'JavaScript code to execute.')->required()
            ->string('description', 'Human-readable description of what the script does.')->required();
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
        return 'ai.tool_write_js.execute';
    }

    protected function metadata(): array
    {
        return [
            'display_name' => 'Write JS',
            'summary' => 'Execute JavaScript in the user\'s browser.',
            'explanation' => 'Sends JavaScript code to be executed client-side in the user\'s browser session. '
                .'Useful for UI interactions and dynamic page modifications.',
            'test_examples' => [
                [
                    'label' => 'Scroll to top',
                    'input' => [
                        'script' => 'window.scrollTo({top: 0, behavior: "smooth"})',
                        'description' => 'Scroll the page to the top',
                    ],
                ],
                [
                    'label' => '⚠ Redirect user to another page',
                    'input' => [
                        'script' => 'window.location.href = "/dashboard"',
                        'description' => 'Navigate user away from current page',
                    ],
                    'runnable' => false,
                ],
            ],
            'limits' => [
                'Executes in the user\'s browser context',
            ],
        ];
    }

    protected function handle(array $arguments): ToolResult
    {
        $script = $this->requireString($arguments, 'script');
        $description = $this->requireString($arguments, 'description');

        if (strlen($script) > self::MAX_SCRIPT_LENGTH) {
            throw new ToolArgumentException('script exceeds maximum length of '.self::MAX_SCRIPT_LENGTH.' characters.');
        }

        if (strlen($description) > self::MAX_DESCRIPTION_LENGTH) {
            throw new ToolArgumentException('description exceeds maximum length of '.self::MAX_DESCRIPTION_LENGTH.' characters.');
        }

        foreach (self::BLOCKED_PATTERNS as $pattern) {
            if (stripos($script, $pattern) !== false) {
                throw new ToolArgumentException('script contains blocked pattern "'.strtolower($pattern).'".');
            }
        }

        return ToolResult::success('<agent-action>'.$script.'</agent-action>Script executed: '.$description.'.');
    }
}
