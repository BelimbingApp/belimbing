<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Base\AI\Tools;

use Stringable;

/**
 * Structured result from a tool execution.
 *
 * Replaces raw string returns with typed results that distinguish success
 * from error and carry client-action payloads as first-class data rather
 * than embedded XML blocks requiring regex extraction.
 *
 * Backward-compatible: implements Stringable so existing code consuming
 * string tool results continues to work without modification.
 */
final readonly class ToolResult implements Stringable
{
    /**
     * @param  string  $content  The primary text content of the result
     * @param  bool  $isError  Whether this result represents an error
     * @param  list<string>  $clientActions  Client-side action payloads (replaces <lara-action> blocks)
     */
    private function __construct(
        public string $content,
        public bool $isError,
        public array $clientActions,
    ) {}

    /**
     * Create a successful result.
     *
     * @param  string  $content  The result content
     */
    public static function success(string $content): self
    {
        return new self($content, false, []);
    }

    /**
     * Create an error result.
     *
     * @param  string  $message  The error message (without 'Error: ' prefix — added automatically)
     */
    public static function error(string $message): self
    {
        return new self('Error: '.$message, true, []);
    }

    /**
     * Create a result with a client-side action.
     *
     * @param  string  $content  Human-readable description of what happened
     * @param  string  $action  The client-side action payload (JavaScript, navigation, etc.)
     */
    public static function withClientAction(string $content, string $action): self
    {
        return new self($content, false, [$action]);
    }

    /**
     * Create a result with multiple client-side actions.
     *
     * @param  string  $content  Human-readable description of what happened
     * @param  list<string>  $actions  Client-side action payloads
     */
    public static function withClientActions(string $content, array $actions): self
    {
        return new self($content, false, $actions);
    }

    /**
     * Render as string for backward compatibility.
     *
     * Embeds client actions as <lara-action> blocks to maintain compatibility
     * with existing AgenticRuntime extraction logic during migration.
     */
    public function __toString(): string
    {
        if ($this->clientActions === []) {
            return $this->content;
        }

        $actionBlocks = '';
        foreach ($this->clientActions as $action) {
            $actionBlocks .= '<lara-action>'.$action.'</lara-action>';
        }

        return $actionBlocks.$this->content;
    }
}
