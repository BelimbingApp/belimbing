<?php

namespace App\Modules\Core\AI\Tools;

use App\Base\AI\Contracts\ProvidesDisplaySummary;
use App\Base\AI\Enums\ToolCategory;
use App\Base\AI\Enums\ToolRiskClass;
use App\Base\AI\Tools\AbstractTool;
use App\Base\AI\Tools\Concerns\ProvidesToolMetadata;
use App\Base\AI\Tools\Schema\ToolSchemaBuilder;
use App\Base\AI\Tools\ToolResult;
use App\Modules\Core\AI\Services\PageContextHolder;

/**
 * Tool for Lara to inspect the user's active Belimbing page in detail.
 *
 * Returns the pre-built, pre-masked PageSnapshot DTO as JSON.
 * Phase 1 metadata (route, title, etc.) is already in the system prompt;
 * this tool provides the richer Phase 2 data (forms, tables, modals).
 */
class ActivePageSnapshotTool extends AbstractTool implements ProvidesDisplaySummary
{
    use ProvidesToolMetadata;

    public function __construct(
        private readonly PageContextHolder $holder,
    ) {}

    public function name(): string
    {
        return 'active_page_snapshot';
    }

    public function description(): string
    {
        return 'Get a detailed snapshot of the user\'s current Belimbing page, including visible form fields, table columns, modal state, and validation errors when available. Use this when you need to understand the page state beyond the basic metadata in the system prompt — for example, to diagnose why save is disabled, inspect form values, or describe table contents.';
    }

    protected function schema(): ?ToolSchemaBuilder
    {
        return null;
    }

    public function category(): ToolCategory
    {
        return ToolCategory::CONTEXT;
    }

    public function riskClass(): ToolRiskClass
    {
        return ToolRiskClass::READ_ONLY;
    }

    public function requiredCapability(): ?string
    {
        return 'admin.ai.tool.active-page-snapshot.view';
    }

    public function displaySummary(array $arguments): string
    {
        return __('Inspect the current page');
    }

    protected function toolMetadata(): array
    {
        return [
            'displayName' => 'Active Page Snapshot',
            'summary' => 'Inspect the user\'s current page for forms, tables, modals, and validation state.',
            'explanation' => 'Returns a structured JSON snapshot of the user\'s active Belimbing page. Includes visible form field values, table metadata, modal state, and validation errors when available. Sensitive fields are masked.',
            'limits' => [
                'Uses a bounded snapshot captured from the user\'s current tab, or a richer component-provided snapshot when available',
                'Sensitive fields are masked by the page snapshot source',
            ],
        ];
    }

    protected function handle(array $arguments): ToolResult
    {
        if (! $this->holder->hasSnapshot()) {
            $context = $this->holder->getContext();

            if ($context !== null) {
                return ToolResult::success(
                    'This page does not provide a detailed snapshot. Basic page info is already in your system prompt: '
                    .$context->toPromptXml()
                );
            }

            return ToolResult::success('No page context is available. The user may not be viewing a Belimbing page.');
        }

        $snapshot = $this->holder->getSnapshot();

        return ToolResult::success(
            json_encode($snapshot->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );
    }
}
