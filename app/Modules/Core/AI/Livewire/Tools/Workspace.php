<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>
//
// Per-tool Workspace — overview, setup configuration, Try It console, and verification.

namespace App\Modules\Core\AI\Livewire\Tools;

use App\Base\Settings\Contracts\SettingsService;
use App\Modules\Core\AI\Enums\ToolReadiness;
use App\Modules\Core\AI\Services\DigitalWorkerToolRegistry;
use App\Modules\Core\AI\Services\ToolMetadataRegistry;
use App\Modules\Core\AI\Services\ToolReadinessService;
use Livewire\Component;

class Workspace extends Component
{
    public string $toolName;

    /** @var array<string, mixed> Config field values keyed by setting key */
    public array $configValues = [];

    /** @var string|null Result from the last Try It execution */
    public ?string $tryItResult = null;

    /** @var bool Whether the Try It execution is in progress */
    public bool $tryItLoading = false;

    /** @var string|null Flash message for config save (success or error) */
    public ?string $configSaved = null;

    /** @var string|null Error message when saving verification status (e.g. DB schema mismatch) */
    public ?string $verificationError = null;

    public function mount(): void
    {
        $this->loadConfigValues();
    }

    /**
     * Save configuration values via the Settings module.
     */
    public function saveConfig(): void
    {
        $settings = app(SettingsService::class);
        $metadata = app(ToolMetadataRegistry::class)->get($this->toolName);

        if (! $metadata) {
            return;
        }

        try {
            foreach ($metadata->configFields as $field) {
                $value = $this->configValues[$field->key] ?? null;

                if ($value === null || $value === '') {
                    $settings->forget($field->key);

                    continue;
                }

                if ($field->type === 'boolean') {
                    $value = (bool) $value;
                }

                $settings->set($field->key, $value, encrypted: $field->encrypted);
            }
        } catch (\Throwable $e) {
            report($e);
            $this->configSaveError = true;
            $this->configSaved = __('Could not save configuration: :message. If the settings table is out of date, run: php artisan migrate', [
                'message' => $e->getMessage(),
            ]);

            return;
        }

        $this->configSaveError = false;
        $this->configSaved = __('Configuration saved.');
        $this->loadConfigValues();
    }

    /**
     * Execute a tool test using a predefined example.
     */
    public function tryIt(int $exampleIndex): void
    {
        $metadata = app(ToolMetadataRegistry::class)->get($this->toolName);

        if (! $metadata || ! isset($metadata->testExamples[$exampleIndex])) {
            $this->tryItResult = __('Example not found.');

            return;
        }

        $registry = app(DigitalWorkerToolRegistry::class);
        $example = $metadata->testExamples[$exampleIndex];

        if (! $registry->isRegistered($this->toolName)) {
            $this->tryItResult = __('Error: This tool is not configured yet. Set the required API key and provider in the Configuration panel, then try again.');

            return;
        }

        try {
            $this->tryItResult = $registry->execute($this->toolName, $example['input']);
        } catch (\Throwable $e) {
            $this->tryItResult = __('Error: :message', ['message' => $e->getMessage()]);
        }

        $this->verificationError = null;
        $this->storeVerification($this->tryItResult);
    }

    public function clearTryItResult(): void
    {
        $this->tryItResult = null;
    }

    public function render(): \Illuminate\Contracts\View\View
    {
        $metadataRegistry = app(ToolMetadataRegistry::class);
        $readinessService = app(ToolReadinessService::class);

        $metadata = $metadataRegistry->get($this->toolName);

        if (! $metadata) {
            return view('livewire.ai.tools.workspace', [
                'metadata' => null,
                'readiness' => ToolReadiness::UNAVAILABLE,
                'lastVerified' => null,
            ]);
        }

        return view('livewire.ai.tools.workspace', [
            'metadata' => $metadata,
            'readiness' => $readinessService->readiness($this->toolName),
            'lastVerified' => $this->getLastVerified(),
        ]);
    }

    /**
     * Load current config values from Settings for display in the form.
     */
    private function loadConfigValues(): void
    {
        $settings = app(SettingsService::class);
        $metadata = app(ToolMetadataRegistry::class)->get($this->toolName);

        if (! $metadata) {
            return;
        }

        foreach ($metadata->configFields as $field) {
            $value = $settings->get($field->key);

            // Mask secret fields that have a value
            if ($field->type === 'secret' && $value !== null && $value !== '') {
                $this->configValues[$field->key] = '';
            } else {
                $this->configValues[$field->key] = $value ?? '';
            }
        }
    }

    /**
     * Store verification result from a Try It execution.
     */
    private function storeVerification(string $result): void
    {
        $isSuccess = ! str_starts_with($result, 'Error:')
            && ! str_starts_with($result, 'Search failed:');

        try {
            $settings = app(SettingsService::class);

            $settings->set("ai.tools.{$this->toolName}.last_verified_at", now()->toIso8601String());
            $settings->set("ai.tools.{$this->toolName}.last_verified_success", $isSuccess);
        } catch (\Throwable $e) {
            report($e);
            $this->verificationError = __('Could not save verification status. The error was: :message. If the settings table is out of date, run: php artisan migrate', [
                'message' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Get the last verification result for this tool.
     *
     * @return array{at: string, success: bool}|null
     */
    private function getLastVerified(): ?array
    {
        $settings = app(SettingsService::class);
        $at = $settings->get("ai.tools.{$this->toolName}.last_verified_at");

        if ($at === null) {
            return null;
        }

        return [
            'at' => $at,
            'success' => (bool) $settings->get("ai.tools.{$this->toolName}.last_verified_success", false),
        ];
    }
}
