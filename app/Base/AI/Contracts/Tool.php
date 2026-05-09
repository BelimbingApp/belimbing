<?php
namespace App\Base\AI\Contracts;

use App\Base\AI\Enums\ToolCategory;
use App\Base\AI\Enums\ToolRiskClass;
use App\Base\AI\Tools\ToolResult;

/**
 * Contract for AI tool implementations.
 *
 * Each tool is a discrete capability that an AI agent can invoke during
 * an agentic conversation turn. Tools are registered in a tool registry
 * and gated by authz capabilities before execution.
 *
 * Tools are deep modules: they self-declare identity, classification,
 * and UI metadata. No external registry needs to know tool-specific
 * details — each tool fully describes itself.
 */
interface Tool
{
    /**
     * Unique tool name (used as the function name in OpenAI tool calling).
     */
    public function name(): string;

    /**
     * Human-friendly display name for UI surfaces (e.g., 'Query Data').
     */
    public function displayName(): string;

    /**
     * Human-readable description for the LLM to understand when to use this tool.
     */
    public function description(): string;

    /**
     * JSON Schema for tool parameters (OpenAI function parameters format).
     *
     * @return array<string, mixed>
     */
    public function parametersSchema(): array;

    /**
     * Authz capability required to use this tool (e.g., 'admin.ai.tool.artisan.execute').
     *
     * Returns null if the tool requires no special capability (auth-only).
     */
    public function requiredCapability(): ?string;

    /**
     * Grouping category for catalog filtering and UI display.
     */
    public function category(): ToolCategory;

    /**
     * Risk classification for safety badges and audit classification.
     */
    public function riskClass(): ToolRiskClass;

    /**
     * One-sentence plain-language summary for humans (UI catalogs, tooltips).
     *
     * Distinct from description(), which is tuned for LLM consumption.
     * Default implementation may return description() as a fallback.
     */
    public function summary(): string;

    /**
     * Longer explanation of what this tool does and does not do.
     *
     * Intended for tool workspace detail panels. Returns empty string
     * if no extended explanation is needed.
     */
    public function explanation(): string;

    /**
     * Human-readable setup checklist items (e.g., 'API key configured').
     *
     * @return list<string>
     */
    public function setupRequirements(): array;

    /**
     * Sample inputs for the Try-It console in the tool workspace.
     *
     * Each entry: ['label' => string, 'input' => array, 'runnable' => bool].
     * The 'runnable' key defaults to true; set to false for display-only examples.
     *
     * @return list<array{label: string, input: array<string, mixed>, runnable?: bool}>
     */
    public function testExamples(): array;

    /**
     * Descriptions of health probes this tool supports.
     *
     * @return list<string>
     */
    public function healthChecks(): array;

    /**
     * Known safety limits users should understand (e.g., 'Maximum 100 rows').
     *
     * @return list<string>
     */
    public function limits(): array;

    /**
     * Execute the tool with the given arguments.
     *
     * Returns a structured ToolResult that carries success/error state,
     * optional error payload with remediation data, and a string
     * representation for LLM consumption.
     *
     * @param  array<string, mixed>  $arguments  Parsed arguments from LLM
     */
    public function execute(array $arguments): ToolResult;
}
