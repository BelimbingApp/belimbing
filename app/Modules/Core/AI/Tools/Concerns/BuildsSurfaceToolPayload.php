<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\AI\Tools\Concerns;

trait BuildsSurfaceToolPayload
{
    /**
     * @param  array<string, mixed>  $arguments
     * @return array{file_path: string, target_surface: string}
     */
    protected function surfaceFilePayload(array $arguments): array
    {
        return [
            'file_path' => $this->requireString($arguments, 'file_path'),
            'target_surface' => $this->optionalString($arguments, 'target_surface') ?? 'core',
        ];
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @param  list<string>  $keys
     * @return array<string, mixed>
     */
    protected function copyPresentKeys(array $arguments, array $keys): array
    {
        $payload = [];

        foreach ($keys as $key) {
            if (array_key_exists($key, $arguments)) {
                $payload[$key] = $arguments[$key];
            }
        }

        return $payload;
    }
}
