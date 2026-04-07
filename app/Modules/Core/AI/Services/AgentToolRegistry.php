<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\AI\Services;

use App\Base\AI\Contracts\StreamableTool;
use App\Base\AI\Contracts\Tool;
use App\Base\AI\Tools\ToolResult;
use App\Base\Authz\Contracts\AuthorizationService;
use App\Base\Authz\DTO\Actor;
use App\Modules\Core\User\Models\User;

/**
 * Registry and executor for Agent tools.
 *
 * Manages tool discovery, builds OpenAI-format tool definitions filtered by
 * the current user's authz capabilities, and dispatches tool execution.
 */
class AgentToolRegistry
{
    private const ERROR_EXECUTING_PREFIX = 'Error executing "';

    /** @var array<string, Tool> */
    private array $tools = [];

    public function __construct(
        private readonly AuthorizationService $authorizationService,
    ) {}

    /**
     * Register a tool.
     */
    public function register(Tool $tool): void
    {
        $this->tools[$tool->name()] = $tool;
    }

    /**
     * Get the names of all registered tools (regardless of authz).
     *
     * @return list<string>
     */
    public function registeredToolNames(): array
    {
        return array_keys($this->tools);
    }

    /**
     * Check whether the current user has permission to use a tool by name.
     */
    public function canCurrentUserUseTool(string $toolName): bool
    {
        $tool = $this->tools[$toolName] ?? null;

        if ($tool === null) {
            return false;
        }

        return $this->currentUserCanUse($tool);
    }

    /**
     * Check whether a tool is registered by name.
     */
    public function isRegistered(string $toolName): bool
    {
        return isset($this->tools[$toolName]);
    }

    /**
     * Get a registered tool by name.
     *
     * @param  string  $toolName  Tool machine name
     */
    public function get(string $toolName): ?Tool
    {
        return $this->tools[$toolName] ?? null;
    }

    /**
     * Get all registered tool instances.
     *
     * @return array<string, Tool>
     */
    public function all(): array
    {
        return $this->tools;
    }

    /**
     * Get OpenAI-format tool definitions for tools the current user can access.
     *
     * @return list<array{type: string, function: array{name: string, description: string, parameters: array<string, mixed>}}>
     */
    public function toolDefinitionsForCurrentUser(): array
    {
        $definitions = [];

        foreach ($this->tools as $tool) {
            if (! $this->currentUserCanUse($tool)) {
                continue;
            }

            $definitions[] = [
                'type' => 'function',
                'function' => [
                    'name' => $tool->name(),
                    'description' => $tool->description(),
                    'parameters' => $tool->parametersSchema(),
                ],
            ];
        }

        return $definitions;
    }

    /**
     * Execute a tool by name with given arguments.
     *
     * Returns a structured ToolResult. Registry-level errors (unknown tool,
     * permission denied, unexpected exception) are wrapped in ToolResult::error()
     * so callers always receive a typed result.
     *
     * @param  array<string, mixed>  $arguments
     */
    public function execute(string $toolName, array $arguments): ToolResult
    {
        $tool = $this->tools[$toolName] ?? null;
        $result = null;

        if ($tool === null) {
            $result = ToolResult::error('Unknown tool "'.$toolName.'".', 'unknown_tool');
        } elseif (! $this->currentUserCanUse($tool)) {
            $result = ToolResult::error(
                'You do not have permission to use the "'.$toolName.'" tool.',
                'permission_denied',
            );
        } else {
            try {
                $result = $tool->execute($arguments);
            } catch (\Throwable $e) {
                $result = $this->toolExecutionFailed($toolName, $e);
            }
        }

        return $result;
    }

    /**
     * Execute a tool with streaming support.
     *
     * For tools implementing StreamableTool, yields incremental output
     * chunks during execution. The ToolResult is accessible via
     * Generator::getReturn() after iteration completes.
     *
     * For non-streaming tools, yields nothing and returns the result
     * from the synchronous execute() path.
     *
     * @param  array<string, mixed>  $arguments
     * @return \Generator<int, string, mixed, ToolResult>
     */
    public function executeStreaming(string $toolName, array $arguments): \Generator
    {
        $tool = $this->tools[$toolName] ?? null;

        if ($tool === null) {
            return ToolResult::error('Unknown tool "'.$toolName.'".', 'unknown_tool');
        }

        if (! $this->currentUserCanUse($tool)) {
            return ToolResult::error(
                'You do not have permission to use the "'.$toolName.'" tool.',
                'permission_denied',
            );
        }

        if ($tool instanceof StreamableTool) {
            try {
                $stream = $tool->executeStreaming($arguments);

                foreach ($stream as $chunk) {
                    yield $chunk;
                }

                return $stream->getReturn();
            } catch (\Throwable $e) {
                return $this->toolExecutionFailed($toolName, $e);
            }
        }

        try {
            return $tool->execute($arguments);
        } catch (\Throwable $e) {
            return $this->toolExecutionFailed($toolName, $e);
        }
    }

    /**
     * Check whether the current user has the capability required by a tool.
     */
    private function currentUserCanUse(Tool $tool): bool
    {
        $capability = $tool->requiredCapability();

        if ($capability === null) {
            return true;
        }

        $user = auth()->user();

        if (! $user instanceof User) {
            return false;
        }

        $actor = Actor::forUser($user);

        return $this->authorizationService->can($actor, $capability)->allowed;
    }

    private function toolExecutionFailed(string $toolName, \Throwable $e): ToolResult
    {
        return ToolResult::error(
            self::ERROR_EXECUTING_PREFIX.$toolName.'": '.$e->getMessage(),
            'unexpected_error',
        );
    }
}
