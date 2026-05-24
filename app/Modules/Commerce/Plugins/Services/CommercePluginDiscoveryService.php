<?php

namespace App\Modules\Commerce\Plugins\Services;

use Illuminate\Support\Facades\Log;

class CommercePluginDiscoveryService
{
    /**
     * @param  list<string>|null  $scanPatterns
     */
    public function __construct(private readonly ?array $scanPatterns = null) {}

    public function discoverInto(CommercePluginRegistry $registry): void
    {
        foreach ($this->files() as $file) {
            $this->loadFile($file, $registry);
        }
    }

    /**
     * @return list<string>
     */
    private function files(): array
    {
        $files = [];

        foreach ($this->scanPatterns ?? $this->defaultScanPatterns() as $pattern) {
            $matches = glob($pattern) ?: [];
            sort($matches);
            array_push($files, ...$matches);
        }

        return array_values(array_unique($files));
    }

    /**
     * @return list<string>
     */
    private function defaultScanPatterns(): array
    {
        return [
            base_path('app/Modules/Commerce/*/Config/commerce.php'),
            base_path('extensions/*/*/Config/commerce.php'),
        ];
    }

    private function loadFile(string $file, CommercePluginRegistry $registry): void
    {
        try {
            $config = require $file;

            if (! is_array($config)) {
                return;
            }

            $this->registerArrayContributions($config, $registry);
            $this->registerStringContributions($config, $registry);
        } catch (\Throwable $e) {
            Log::warning('Failed to load Commerce plugin contribution file', [
                'file' => $file,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function registerArrayContributions(array $config, CommercePluginRegistry $registry): void
    {
        $registrations = [
            'catalog_presets' => 'registerCatalogPreset',
            'marketplace_template_mappings' => 'registerMarketplaceTemplateMapping',
            'workbench_panels' => 'registerWorkbenchPanel',
            'insight_pages' => 'registerInsightPage',
        ];

        foreach ($registrations as $key => $method) {
            foreach ($config[$key] ?? [] as $entry) {
                if (is_array($entry)) {
                    $registry->{$method}($entry);
                }
            }
        }
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function registerStringContributions(array $config, CommercePluginRegistry $registry): void
    {
        $registrations = [
            'marketplace_channel_providers' => 'registerMarketplaceChannelProvider',
            'readiness_contributors' => 'registerReadinessContributor',
        ];

        foreach ($registrations as $key => $method) {
            foreach ($config[$key] ?? [] as $entry) {
                if (is_string($entry)) {
                    $registry->{$method}($entry);
                }
            }
        }
    }
}
