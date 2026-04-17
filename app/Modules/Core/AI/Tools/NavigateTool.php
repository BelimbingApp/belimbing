<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\AI\Tools;

use App\Base\AI\Enums\ToolCategory;
use App\Base\AI\Enums\ToolRiskClass;
use App\Base\AI\Tools\AbstractTool;
use App\Base\AI\Tools\Concerns\ProvidesToolMetadata;
use App\Base\AI\Tools\Schema\ToolSchemaBuilder;
use App\Base\AI\Tools\ToolArgumentException;
use App\Base\AI\Tools\ToolResult;

/**
 * Browser navigation tool for agents.
 *
 * Allows an agent to navigate the user's browser to BLB pages.
 * Returns an `<agent-action>` block that the client-side executor handles.
 *
 * Gated by `ai.tool_navigate.execute` authz capability.
 */
class NavigateTool extends AbstractTool
{
    use ProvidesToolMetadata;

    public function name(): string
    {
        return 'navigate';
    }

    public function description(): string
    {
        return 'Navigate the user\'s browser to a BLB page. '
            .'Use this when the user asks to go to a page, or after completing a task to show results. '
            .'When unsure of the path, call visible_nav_menu first for paths this user may open. '
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

    protected function metadata(): array
    {
        return [
            'display_name' => 'Navigate',
            'summary' => 'Navigate the user to a page within BLB.',
            'explanation' => 'Triggers client-side SPA navigation to a BLB page. '
                .'The LLM uses this to direct users to relevant screens. '
                .'Navigation is limited to internal BLB routes.',
            'limits' => [
                'Internal BLB routes only',
            ],
        ];
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

        return ToolResult::success('<agent-action>Livewire.navigate(\''.$url.'\')</agent-action>Navigation initiated to '.$url.'.');
    }
}
