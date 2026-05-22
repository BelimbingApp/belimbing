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

            foreach ($config['catalog_presets'] ?? [] as $preset) {
                if (is_array($preset)) {
                    $registry->registerCatalogPreset($preset);
                }
            }

            foreach ($config['marketplace_channel_providers'] ?? [] as $provider) {
                if (is_string($provider)) {
                    $registry->registerMarketplaceChannelProvider($provider);
                }
            }

            foreach ($config['marketplace_template_mappings'] ?? [] as $mapping) {
                if (is_array($mapping)) {
                    $registry->registerMarketplaceTemplateMapping($mapping);
                }
            }

            foreach ($config['readiness_contributors'] ?? [] as $contributor) {
                if (is_string($contributor)) {
                    $registry->registerReadinessContributor($contributor);
                }
            }

            foreach ($config['workbench_panels'] ?? [] as $panel) {
                if (is_array($panel)) {
                    $registry->registerWorkbenchPanel($panel);
                }
            }

            foreach ($config['insight_pages'] ?? [] as $page) {
                if (is_array($page)) {
                    $registry->registerInsightPage($page);
                }
            }
        } catch (\Throwable $e) {
            Log::warning('Failed to load Commerce plugin contribution file', [
                'file' => $file,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
