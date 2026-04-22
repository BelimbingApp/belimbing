<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\AI\Livewire\Concerns;

use App\Base\AI\DTO\ExecutionControls;
use App\Base\AI\Enums\AiApiType;
use App\Base\AI\Services\ExecutionControlSchemaFactory;

trait ManagesExecutionControls
{
    /**
     * @param  array<string, mixed>|null  $config
     * @return array<string, mixed>
     */
    protected function hydrateExecutionControlsConfig(?array $config): array
    {
        return app(ExecutionControlSchemaFactory::class)->normalize(is_array($config) ? $config : []);
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    protected function normalizeExecutionControlsConfig(array $config): array
    {
        return app(ExecutionControlSchemaFactory::class)->normalize($config);
    }

    protected function defaultExecutionControls(): ExecutionControls
    {
        return app(ExecutionControlSchemaFactory::class)->defaultControls();
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    protected function executionControlSchema(
        ?string $providerName,
        string $model,
        AiApiType $apiType,
        array $config,
    ): array {
        return app(ExecutionControlSchemaFactory::class)->build(
            providerName: $providerName,
            model: $model,
            apiType: $apiType,
            controls: ExecutionControls::fromConfig($config, $this->defaultExecutionControls()),
        );
    }
}
