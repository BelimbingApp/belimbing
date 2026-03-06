<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\AI\Services;

use App\Base\Authz\Contracts\AuthorizationService;
use App\Base\Authz\DTO\Actor;
use App\Base\Authz\Enums\PrincipalType;
use App\Modules\Core\User\Models\User;

/**
 * Deterministic navigation router for Lara browser control.
 *
 * Resolves navigation targets from explicit `/go` commands and natural-language
 * intents. Each target maps to a named route and an optional authz capability.
 * When a capability is declared, the router checks the current user's permission
 * before emitting the navigation payload.
 */
class LaraNavigationRouter
{
    public function __construct(
        private readonly AuthorizationService $authorizationService,
    ) {}

    /**
     * Attempt to resolve a navigation action from user input.
     *
     * Returns null when the message is not a navigation intent.
     * Returns a structured result with status, target, and optional navigation payload.
     *
     * @return array{status: string, target?: string, navigation?: array{strategy: string, url: string, label: string, target: string}, message: string}|null
     */
    public function resolve(string $message): ?array
    {
        $target = $this->extractExplicitTarget($message)
            ?? $this->detectNavigationIntent($message);

        if ($target === null) {
            return null;
        }

        if ($target === '') {
            return [
                'status' => 'invalid_navigation_command',
                'message' => __('Use "/go <target>" — available: :targets.', [
                    'targets' => implode(', ', $this->availableTargetNames()),
                ]),
            ];
        }

        $config = $this->targets()[$target] ?? null;

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

        $url = route($config['route'], [], false);

        return [
            'status' => 'navigation',
            'target' => $target,
            'navigation' => [
                'strategy' => 'js_go_to_url',
                'url' => $url,
                'label' => $config['label'],
                'target' => $target,
            ],
            'message' => __('Navigating to :label.', ['label' => $config['label']]),
        ];
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

        if (! str_starts_with($trimmed, '/go')) {
            return null;
        }

        return mb_strtolower(trim((string) substr($trimmed, strlen('/go'))));
    }

    /**
     * Detect navigation intent from natural-language input.
     *
     * Uses keyword matching against the aliases declared per target.
     * Returns the best matching target key, or null when no intent detected.
     */
    private function detectNavigationIntent(string $message): ?string
    {
        $lower = mb_strtolower(trim($message));

        // Require a navigation verb to avoid false positives on partial keyword matches.
        if (! $this->containsNavigationVerb($lower)) {
            return null;
        }

        $bestTarget = null;
        $bestScore = 0;

        foreach ($this->targets() as $key => $config) {
            $score = $this->scoreKeywordMatch($lower, $config['aliases']);

            if ($score > $bestScore) {
                $bestTarget = $key;
                $bestScore = $score;
            }
        }

        return $bestTarget;
    }

    /**
     * Check whether the message contains a navigation-intent verb.
     */
    private function containsNavigationVerb(string $lower): bool
    {
        $verbs = [
            'go to', 'navigate to', 'open', 'show me', 'take me to',
            'bring up', 'switch to', 'visit', 'head to', 'jump to',
        ];

        foreach ($verbs as $verb) {
            if (str_contains($lower, $verb)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Score how well the message matches a target's keyword aliases.
     *
     * @param  list<string>  $aliases
     */
    private function scoreKeywordMatch(string $lower, array $aliases): int
    {
        $score = 0;

        foreach ($aliases as $alias) {
            if (str_contains($lower, $alias)) {
                // Longer alias matches are more specific → higher score.
                $score += mb_strlen($alias);
            }
        }

        return $score;
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

        $companyId = $user->getAttribute('company_id');

        $actor = new Actor(
            type: PrincipalType::HUMAN_USER,
            id: (int) $user->getAuthIdentifier(),
            companyId: $companyId !== null ? (int) $companyId : null,
        );

        return $this->authorizationService->can($actor, $capability)->allowed;
    }

    /**
     * Navigation target registry.
     *
     * Each entry maps a canonical target key to:
     * - route:      Named Laravel route
     * - label:      Human-readable label for UI feedback
     * - capability: Authz capability required (null = auth-only)
     * - aliases:    Natural-language keyword triggers for intent detection
     *
     * @return array<string, array{route: string, label: string, capability: string|null, aliases: list<string>}>
     */
    private function targets(): array
    {
        return [
            'dashboard' => [
                'route' => 'dashboard',
                'label' => __('Dashboard'),
                'capability' => null,
                'aliases' => ['dashboard', 'home', 'overview'],
            ],
            'users' => [
                'route' => 'admin.users.index',
                'label' => __('Users'),
                'capability' => 'core.user.list',
                'aliases' => ['users', 'user list', 'user management'],
            ],
            'companies' => [
                'route' => 'admin.companies.index',
                'label' => __('Companies'),
                'capability' => null,
                'aliases' => ['companies', 'company list', 'company management'],
            ],
            'employees' => [
                'route' => 'admin.employees.index',
                'label' => __('Employees'),
                'capability' => null,
                'aliases' => ['employees', 'employee list', 'employee management', 'staff'],
            ],
            'roles' => [
                'route' => 'admin.roles.index',
                'label' => __('Roles'),
                'capability' => 'admin.role.list',
                'aliases' => ['roles', 'role management', 'permissions'],
            ],
            'addresses' => [
                'route' => 'admin.addresses.index',
                'label' => __('Addresses'),
                'capability' => null,
                'aliases' => ['addresses', 'address list', 'address management'],
            ],
            'providers' => [
                'route' => 'admin.ai.providers',
                'label' => __('AI Providers'),
                'capability' => null,
                'aliases' => ['providers', 'ai providers', 'model providers'],
            ],
            'models' => [
                'route' => 'admin.ai.providers',
                'label' => __('AI Providers'),
                'capability' => null,
                'aliases' => ['ai models', 'model list'],
            ],
            'playground' => [
                'route' => 'admin.ai.playground',
                'label' => __('AI Playground'),
                'capability' => null,
                'aliases' => ['playground', 'ai playground', 'test model', 'try model'],
            ],
            'setup-lara' => [
                'route' => 'admin.setup.lara',
                'label' => __('Lara Setup'),
                'capability' => null,
                'aliases' => ['setup lara', 'lara setup', 'configure lara', 'activate lara'],
            ],
        ];
    }
}
