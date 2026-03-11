<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\AI\Tools;

use App\Base\AI\Enums\ToolCategory;
use App\Base\AI\Enums\ToolRiskClass;
use App\Base\AI\Tools\AbstractTool;
use App\Base\AI\Tools\Schema\ToolSchemaBuilder;
use App\Base\AI\Tools\ToolArgumentException;
use App\Base\AI\Tools\ToolResult;

/**
 * Client-side JavaScript execution tool for Digital Workers.
 *
 * Allows a DW to execute JavaScript in the user's browser via a
 * `<lara-action>` block that the client-side executor handles.
 * Scripts are validated for safety before execution.
 *
 * Gated by `ai.tool_write_js.execute` authz capability.
 */
class WriteJsTool extends AbstractTool
{
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
            .'Returns a lara-action block for safe client-side execution. '
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

        return ToolResult::success('<lara-action>'.$script.'</lara-action>Script executed: '.$description.'.');
    }
}
