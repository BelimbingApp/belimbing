<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\AI\Services;

use App\Base\Authz\Contracts\AuthorizationService;
use App\Base\Authz\DTO\Actor;
use App\Base\Support\Str as BlbStr;
use App\Modules\Core\User\Models\User;

/**
 * Deterministic navigation router for explicit `/go` commands.
 *
 * Resolves navigation targets from explicit `/go <target>` user input only.
 * Natural-language navigation is delegated to the LLM, which outputs
 * `<agent-action>` JS blocks for client-side execution.
 *
 * Each target maps to a named route and an optional authz capability.
 * When a capability is declared, the router checks the current user's
 * permission before emitting the navigation payload.
 */
class LaraNavigationRouter
{
    public function __construct(
        private readonly AuthorizationService $authorizationService,
    ) {}

    /**
     * Attempt to resolve a navigation action from explicit `/go` user input.
     *
     * Returns null when the message is not a `/go` command.
     * Returns a structured result with status, target, and optional navigation payload.
     *
     * @return array{status: string, target?: string, navigation?: array{strategy: string, url: string, label: string, target: string}, message: string}|null
     */
    public function resolve(string $message): ?array
    {
        $target = $this->extractExplicitTarget($message);

        if ($target === null) {
            return null;
        }

        $response = $this->invalidTargetResponse($target);

        if ($response === null) {
            $config = $this->targets()[$target] ?? null;
            $response = $this->configuredTargetResponse($target, $config);
        }

        return $response;
    }

    /**
     * Return the list of targets the current user can access.
     *
     * @return list<string>
     */
    public function availableTargetNames(): array
    {
        $names = [];

        foreach ($this->targets() as $key => $config) {
            if ($config['capability'] === null || $this->currentUserCan($config['capability'])) {
                $names[] = $key;
            }
        }

        return $names;
    }

    /**
     * Extract explicit `/go <target>` from user input.
     */
    private function extractExplicitTarget(string $message): ?string
    {
        $trimmed = trim($message);

        return str_starts_with($trimmed, '/go')
            ? mb_strtolower(BlbStr::afterPrefix($trimmed, '/go'))
            : null;
    }

    /**
     * Check whether the current user has the given capability.
     */
    private function currentUserCan(string $capability): bool
    {
        $user = auth()->user();

        if (! $user instanceof User) {
            return false;
        }

        $actor = Actor::forUser($user);

        return $this->authorizationService->can($actor, $capability)->allowed;
    }

    /**
     * @return array{status: string, message: string, target?: string}|null
     */
    private function invalidTargetResponse(string $target): ?array
    {
        return $target === ''
            ? [
                'status' => 'invalid_navigation_command',
                'message' => __('Use "/go <target>" — available: :targets.', [
                    'targets' => implode(', ', $this->availableTargetNames()),
                ]),
            ]
            : null;
    }

    /**
     * @param  array{route: string, label: string, capability: string|null}|null  $config
     * @return array{status: string, target?: string, navigation?: array{strategy: string, url: string, label: string, target: string}, message: string}
     */
    private function configuredTargetResponse(string $target, ?array $config): array
    {
        if ($config === null) {
            return [
                'status' => 'unknown_navigation_target',
                'target' => $target,
                'message' => __('Unknown target ":target". Available: :targets.', [
                    'target' => $target,
                    'targets' => implode(', ', $this->availableTargetNames()),
                ]),
            ];
        }

        if ($config['capability'] !== null && ! $this->currentUserCan($config['capability'])) {
            return [
                'status' => 'navigation_denied',
                'target' => $target,
                'message' => __('You don\'t have permission to access :label.', [
                    'label' => $config['label'],
                ]),
            ];
        }

        return [
            'status' => 'navigation',
            'target' => $target,
            'navigation' => [
                'strategy' => 'js_go_to_url',
                'url' => route($config['route'], [], false),
                'label' => $config['label'],
                'target' => $target,
            ],
            'message' => __('Navigating to :label.', ['label' => $config['label']]),
        ];
    }

    /**
     * Navigation target registry for explicit `/go` commands.
     *
     * Each entry maps a canonical target key to:
     * - route:      Named Laravel route
     * - label:      Human-readable label for UI feedback
     * - capability: Authz capability required (null = auth-only)
     *
     * @return array<string, array{route: string, label: string, capability: string|null}>
     */
    private function targets(): array
    {
        return [
            'dashboard' => [
                'route' => 'dashboard',
                'label' => __('Dashboard'),
                'capability' => null,
            ],
            'users' => [
                'route' => 'admin.users.index',
                'label' => __('Users'),
                'capability' => 'core.user.list',
            ],
            'companies' => [
                'route' => 'admin.companies.index',
                'label' => __('Companies'),
                'capability' => null,
            ],
            'employees' => [
                'route' => 'admin.employees.index',
                'label' => __('Employees'),
                'capability' => null,
            ],
            'roles' => [
                'route' => 'admin.roles.index',
                'label' => __('Roles'),
                'capability' => 'admin.role.list',
            ],
            'addresses' => [
                'route' => 'admin.addresses.index',
                'label' => __('Addresses'),
                'capability' => null,
            ],
            'providers' => [
                'route' => 'admin.ai.providers',
                'label' => __('AI Providers'),
                'capability' => null,
            ],
            'models' => [
                'route' => 'admin.ai.providers',
                'label' => __('AI Providers'),
                'capability' => null,
            ],
            'playground' => [
                'route' => 'admin.ai.playground',
                'label' => __('AI Playground'),
                'capability' => null,
            ],
            'setup-lara' => [
                'route' => 'admin.setup.lara',
                'label' => __('Lara Setup'),
                'capability' => null,
            ],
        ];
    }
}
