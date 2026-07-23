<?php

namespace App\Base\Database\Services\Backup;

use App\Base\Settings\Contracts\SettingsService;

/**
 * Projects declared backup parameters onto environment-owned infrastructure
 * config such as the source connection, local override disk, and KMS material.
 */
final readonly class BackupRuntimeSettings
{
    public function __construct(private SettingsService $settings) {}

    /**
     * @return array<string, mixed>
     */
    public function configuration(): array
    {
        $base = (array) config('backup', []);

        return array_replace($base, [
            'enabled' => (bool) $this->settings->get('backup.enabled'),
            'disk' => (string) $this->settings->get('backup.disk'),
            'path_prefix' => (string) $this->settings->get('backup.path_prefix'),
            'encryption' => array_replace($base['encryption'] ?? [], [
                'mode' => (string) $this->settings->get('backup.encryption.mode'),
            ]),
            'retention' => array_replace($base['retention'] ?? [], [
                'keep_days' => (int) $this->settings->get('backup.retention.keep_days'),
                'keep_count' => (int) $this->settings->get('backup.retention.keep_count'),
            ]),
        ]);
    }
}
