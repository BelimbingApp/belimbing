<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

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
 * Tools self-declare their category and risk class, eliminating the need
 * for parallel metadata registries to maintain this information separately.
 */
interface Tool
{
    /**
     * Unique tool name (used as the function name in OpenAI tool calling).
     */
    public function name(): string;

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
     * Authz capability required to use this tool (e.g., 'ai.tool_artisan.execute').
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
