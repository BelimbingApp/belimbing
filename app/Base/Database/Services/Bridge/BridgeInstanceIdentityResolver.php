<?php

namespace App\Base\Database\Services\Bridge;

use App\Base\Database\DTO\Bridge\BridgeInstanceIdentity;
use App\Base\Database\Enums\BridgeInstanceRole;
use App\Base\Database\Exceptions\BridgePolicyException;

class BridgeInstanceIdentityResolver
{
    public function __construct(private readonly BridgeSettings $settings) {}

    public function current(): BridgeInstanceIdentity
    {
        $role = $this->role();
        $configuredId = $this->settings->string('bridge.instance.id');
        $id = $configuredId !== ''
            ? $configuredId
            : $this->fallbackId($role);

        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]*$/', $id) !== 1) {
            throw BridgePolicyException::invalidSetting('bridge.instance.id');
        }

        return new BridgeInstanceIdentity(
            id: $id,
            name: $this->settings->string('bridge.instance.name', (string) config('app.name')) ?: $id,
            role: $role,
        );
    }

    public function role(): BridgeInstanceRole
    {
        $configured = $this->settings->string('bridge.instance.role');

        if ($configured !== '') {
            return BridgeInstanceRole::tryFrom($configured)
                ?? throw BridgePolicyException::invalidRole($configured);
        }

        return match ((string) config('app.env')) {
            'local', 'testing' => BridgeInstanceRole::Development,
            'staging' => BridgeInstanceRole::Staging,
            'production' => BridgeInstanceRole::Production,
            default => throw BridgePolicyException::invalidRole((string) config('app.env')),
        };
    }

    private function fallbackId(BridgeInstanceRole $role): string
    {
        $prefix = match ($role) {
            BridgeInstanceRole::Development => 'dev',
            BridgeInstanceRole::Staging => 'stage',
            BridgeInstanceRole::Production => 'prod',
        };

        return $prefix.'-'.substr(hash('sha256', base_path().'|'.(string) config('app.url')), 0, 20);
    }
}
