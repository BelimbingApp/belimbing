<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Base\AI\Contracts;

use App\Base\AI\Tools\ToolResult;

/**
 * Contract for tools that support incremental output streaming.
 *
 * Tools implementing this interface yield output chunks during execution,
 * enabling real-time visibility into long-running operations (e.g., bash
 * commands producing progressive stdout).
 *
 * The generator yields string chunks as they become available. The final
 * ToolResult is returned via Generator::getReturn() after iteration
 * completes. Callers must iterate the generator to completion before
 * accessing the return value.
 *
 * Tools that don't implement this interface execute synchronously via
 * the standard Tool::execute() path — results appear only at completion.
 */
interface StreamableTool extends Tool
{
    /**
     * Execute the tool with incremental output streaming.
     *
     * @param  array<string, mixed>  $arguments  Parsed arguments from LLM
     * @return \Generator<int, string, mixed, ToolResult> Yields output chunks, returns final result
     */
    public function executeStreaming(array $arguments): \Generator;
}
