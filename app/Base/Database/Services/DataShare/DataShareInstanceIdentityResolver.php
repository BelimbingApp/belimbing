<?php

namespace App\Base\Database\Services\DataShare;

use App\Base\Database\DTO\DataShare\DataShareInstanceIdentity;
use App\Base\Database\Enums\DataShareInstanceRole;
use App\Base\Database\Exceptions\DataSharePolicyException;

class DataShareInstanceIdentityResolver
{
    public function __construct(private readonly DataShareSettings $settings) {}

    public function current(): DataShareInstanceIdentity
    {
        $role = $this->role();
        $configuredId = $this->settings->string('data_share.instance.id');
        $id = $configuredId !== ''
            ? $configuredId
            : $this->fallbackId($role);

        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]*$/', $id) !== 1) {
            throw DataSharePolicyException::invalidSetting('data_share.instance.id');
        }

        return new DataShareInstanceIdentity(
            id: $id,
            name: $this->settings->string('data_share.instance.name', (string) config('app.name')) ?: $id,
            role: $role,
        );
    }

    public function role(): DataShareInstanceRole
    {
        $configured = $this->settings->string('data_share.instance.role');

        if ($configured !== '') {
            return DataShareInstanceRole::tryFrom($configured)
                ?? throw DataSharePolicyException::invalidRole($configured);
        }

        return match ((string) config('app.env')) {
            'local', 'testing' => DataShareInstanceRole::Development,
            'staging' => DataShareInstanceRole::Staging,
            'production' => DataShareInstanceRole::Production,
            default => throw DataSharePolicyException::invalidRole((string) config('app.env')),
        };
    }

    private function fallbackId(DataShareInstanceRole $role): string
    {
        $prefix = match ($role) {
            DataShareInstanceRole::Development => 'dev',
            DataShareInstanceRole::Staging => 'stage',
            DataShareInstanceRole::Production => 'prod',
        };

        return $prefix.'-'.substr(hash('sha256', base_path().'|'.(string) config('app.url')), 0, 20);
    }
}
