<?php

namespace App\Base\System\Services;

class SystemHealthProbe
{
    /**
     * @return list<array{key: string, label: string, path: string, exists: bool, writable: bool}>
     */
    public function writablePaths(): array
    {
        return array_map(function (array $path): array {
            $absolutePath = $path['path'];

            return [
                'key' => $path['key'],
                'label' => $path['label'],
                'path' => $absolutePath,
                'exists' => is_dir($absolutePath),
                'writable' => is_writable($absolutePath),
            ];
        }, $this->requiredWritablePaths());
    }

    /**
     * @return list<array{key: string, label: string, path: string, exists: bool, writable: bool}>
     */
    public function unwritablePaths(): array
    {
        return array_values(array_filter(
            $this->writablePaths(),
            fn (array $path): bool => ! $path['exists'] || ! $path['writable'],
        ));
    }

    /**
     * @return list<array{key: string, label: string, path: string}>
     */
    private function requiredWritablePaths(): array
    {
        return [
            ['key' => 'storage.app', 'label' => 'storage/app', 'path' => storage_path('app')],
            ['key' => 'storage.logs', 'label' => 'storage/logs', 'path' => storage_path('logs')],
            ['key' => 'storage.framework.cache', 'label' => 'storage/framework/cache', 'path' => storage_path('framework/cache')],
            ['key' => 'storage.framework.sessions', 'label' => 'storage/framework/sessions', 'path' => storage_path('framework/sessions')],
            ['key' => 'storage.framework.views', 'label' => 'storage/framework/views', 'path' => storage_path('framework/views')],
            ['key' => 'bootstrap.cache', 'label' => 'bootstrap/cache', 'path' => base_path('bootstrap/cache')],
        ];
    }
}
