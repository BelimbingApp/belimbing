<?php

namespace App\Modules\Core\AI\Tools\Concerns;

trait BuildsSurfaceToolPayload
{
    /**
     * @param  list<string>  $targets
     * @return array{type: string, description: string, enum: list<string>}
     */
    protected function repositoryTargetSchema(string $verb, array $targets = ['file', 'data']): array
    {
        return [
            'type' => 'string',
            'description' => $verb.' target: "file" or "data". Defaults to "file".',
            'enum' => $targets,
        ];
    }

    /**
     * @return array{type: string, description: string}
     */
    protected function repositoryFilePathSchema(): array
    {
        return [
            'type' => 'string',
            'description' => 'Path relative to the selected target surface when target is "file".',
        ];
    }

    /**
     * @return array{type: string, description: string}
     */
    protected function repositorySurfaceSchema(string $operation): array
    {
        return [
            'type' => 'string',
            'description' => 'Repository ownership surface for file '.$operation.': "core" or "extension:<slug>". Defaults to "core".',
        ];
    }

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
