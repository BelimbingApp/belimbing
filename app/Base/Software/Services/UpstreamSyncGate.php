<?php

namespace App\Base\Software\Services;

use App\Base\Authz\Contracts\AuthorizationService;
use App\Base\Authz\DTO\Actor;
use App\Base\Settings\Contracts\SettingsService;
use App\Base\Software\Enums\SoftwareDeploymentRole;
use App\Base\Software\Exceptions\UpstreamSyncUnavailableException;

/**
 * Server-side boundary for framework-upstream synchronization (lane 2 of #339).
 *
 * Fail closed: unset, unrecognised, or production roles deny sync. APP_ENV
 * production also denies even if a development role was copied in. Capability
 * checks are separate so Updates rights never imply Sync rights.
 */
final class UpstreamSyncGate
{
    public const string ROLE_SETTING_KEY = 'software.deployment.role';

    public const string CAPABILITY = 'admin.system.software.upstream-sync.manage';

    public function __construct(
        private readonly SettingsService $settings,
        private readonly AuthorizationService $authorization,
    ) {}

    /**
     * Role / environment gate only — does not consult authorization.
     *
     * @return array{allowed: bool, reason_code: string|null, role: string|null}
     */
    public function roleAvailability(): array
    {
        $raw = $this->configuredRoleRaw();
        $role = SoftwareDeploymentRole::tryFrom($raw ?? '');

        if ($raw === null || $raw === '') {
            return ['allowed' => false, 'reason_code' => 'unset', 'role' => null];
        }

        if ($role === null) {
            return ['allowed' => false, 'reason_code' => 'unrecognised', 'role' => $raw];
        }

        if ($role === SoftwareDeploymentRole::Production) {
            return ['allowed' => false, 'reason_code' => 'production_role', 'role' => $role->value];
        }

        if ($this->installationIsProduction()) {
            return ['allowed' => false, 'reason_code' => 'production_env', 'role' => $role->value];
        }

        if (! $role->allowsUpstreamSync()) {
            return ['allowed' => false, 'reason_code' => 'role_closed', 'role' => $role->value];
        }

        return ['allowed' => true, 'reason_code' => null, 'role' => $role->value];
    }

    /**
     * Full gate for an actor: role/environment plus capability.
     *
     * @return array{
     *     allowed: bool,
     *     reason_code: string|null,
     *     role: string|null,
     *     role_allows: bool,
     *     has_capability: bool,
     * }
     */
    public function availability(Actor $actor): array
    {
        $roleGate = $this->roleAvailability();
        $hasCapability = $this->authorization->can($actor, self::CAPABILITY)->allowed;

        if (! $roleGate['allowed']) {
            return [
                'allowed' => false,
                'reason_code' => $roleGate['reason_code'],
                'role' => $roleGate['role'],
                'role_allows' => false,
                'has_capability' => $hasCapability,
            ];
        }

        if (! $hasCapability) {
            return [
                'allowed' => false,
                'reason_code' => 'capability',
                'role' => $roleGate['role'],
                'role_allows' => true,
                'has_capability' => false,
            ];
        }

        return [
            'allowed' => true,
            'reason_code' => null,
            'role' => $roleGate['role'],
            'role_allows' => true,
            'has_capability' => true,
        ];
    }

    public function assertCanSync(Actor $actor): void
    {
        $availability = $this->availability($actor);

        if ($availability['allowed']) {
            return;
        }

        throw new UpstreamSyncUnavailableException(
            $this->reasonMessage($availability['reason_code'] ?? 'closed'),
        );
    }

    public function reasonMessage(string $reasonCode): string
    {
        return match ($reasonCode) {
            'unset' => (string) __('Upstream synchronization is unavailable until an operator sets the Software deployment role to development or staging.'),
            'unrecognised' => (string) __('Upstream synchronization is unavailable because the Software deployment role is not recognised.'),
            'production_role' => (string) __('Upstream synchronization is unavailable while the Software deployment role is production.'),
            'production_env' => (string) __('Upstream synchronization is unavailable on a production installation.'),
            'capability' => (string) __('You do not have permission to synchronize with the framework upstream.'),
            'role_closed' => (string) __('Upstream synchronization is unavailable for this Software deployment role.'),
            default => (string) __('Upstream synchronization is unavailable.'),
        };
    }

    private function configuredRoleRaw(): ?string
    {
        $value = $this->settings->get(self::ROLE_SETTING_KEY);

        if ($value === null) {
            return null;
        }

        return is_string($value) ? trim($value) : null;
    }

    private function installationIsProduction(): bool
    {
        return (string) config('app.env') === 'production';
    }
}
