<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\AI\Services;

use App\Base\AI\Contracts\Tool;
use App\Base\Authz\Contracts\AuthorizationService;
use App\Base\Authz\DTO\Actor;
use App\Base\Authz\Enums\PrincipalType;
use App\Modules\Core\User\Models\User;

/**
 * Registry and executor for Digital Worker tools.
 *
 * Manages tool discovery, builds OpenAI-format tool definitions filtered by
 * the current user's authz capabilities, and dispatches tool execution.
 */
class DigitalWorkerToolRegistry
{
    private const ERROR_PREFIX = 'Error: ';

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
     * Returns the tool result string, or an error message if the tool is
     * unknown or the user lacks permission.
     */
    public function execute(string $toolName, array $arguments): string
    {
        $tool = $this->tools[$toolName] ?? null;
        $response = null;

        if ($tool === null) {
            $response = self::ERROR_PREFIX.'Unknown tool "'.$toolName.'".';
        }

        if ($tool !== null && ! $this->currentUserCanUse($tool)) {
            $response = self::ERROR_PREFIX.'You do not have permission to use the "'.$toolName.'" tool.';
        }

        if ($response === null && $tool !== null) {
            try {
                $response = $tool->execute($arguments);
            } catch (\Throwable $e) {
                $response = self::ERROR_PREFIX.'Error executing "'.$toolName.'": '.$e->getMessage();
            }
        }

        return $response;
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

        $companyId = $user->getAttribute('company_id');

        $actor = new Actor(
            type: PrincipalType::HUMAN_USER,
            id: (int) $user->getAuthIdentifier(),
            companyId: $companyId !== null ? (int) $companyId : null,
        );

        return $this->authorizationService->can($actor, $capability)->allowed;
    }
}
