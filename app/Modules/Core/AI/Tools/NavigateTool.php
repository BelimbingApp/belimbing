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
 * Browser navigation tool for Digital Workers.
 *
 * Allows a DW to navigate the user's browser to BLB pages.
 * Returns a `<lara-action>` block that the client-side executor handles.
 *
 * Gated by `ai.tool_navigate.execute` authz capability.
 */
class NavigateTool extends AbstractTool
{
    public function name(): string
    {
        return 'navigate';
    }

    public function description(): string
    {
        return 'Navigate the user\'s browser to a BLB page. '
            .'Use this when the user asks to go to a page, or after completing a task to show results. '
            .'Provide the relative URL path (e.g., "/admin/users", "/admin/geonames/postcodes", "/dashboard").';
    }

    protected function schema(): ToolSchemaBuilder
    {
        return ToolSchemaBuilder::make()
            ->string(
                'url',
                'Relative URL path to navigate to (must start with "/").'
            )->required();
    }

    public function category(): ToolCategory
    {
        return ToolCategory::BROWSER;
    }

    public function riskClass(): ToolRiskClass
    {
        return ToolRiskClass::INTERNAL;
    }

    public function requiredCapability(): ?string
    {
        return 'ai.tool_navigate.execute';
    }

    protected function handle(array $arguments): ToolResult
    {
        $url = $this->requireString($arguments, 'url');

        if (! str_starts_with($url, '/')) {
            throw new ToolArgumentException('URL must be a relative path starting with "/".');
        }

        // Sanitize: only allow path characters
        if (preg_match('#^/[a-zA-Z0-9/_\-\.]+$#', $url) !== 1) {
            throw new ToolArgumentException('URL contains invalid characters.');
        }

        return ToolResult::success('<lara-action>Livewire.navigate(\''.$url.'\')</lara-action>Navigation initiated to '.$url.'.');
    }
}
