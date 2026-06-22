<?php

namespace App\Base\Software\Services;

use App\Base\Software\Inventory\Contracts\InventoryContributionProvider;
use Throwable;

/**
 * Discovers inventory contribution providers declared in `Config/inventory.php`
 * across modules and extensions, and registers them into InventoryContributionRegistry.
 *
 * A host module advertises its seam's provider by class:
 *
 *     return ['contribution_providers' => [\App\Modules\…\SomeContributionProvider::class]];
 *
 * Modelled on the Commerce/Payroll discovery seams. Tolerant by design: this feeds a
 * read-only operator report, so a provider that fails to construct or describe itself
 * is skipped rather than breaking the inventory — the opposite trade-off from payroll
 * country packs, where a missing pack must fail loudly.
 */
class InventoryContributionDiscoveryService
{
    /**
     * @param  list<string>|null  $scanPatterns  glob patterns; null uses the defaults.
     */
    public function __construct(private readonly ?array $scanPatterns = null) {}

    public function discoverInto(InventoryContributionRegistry $registry): void
    {
        foreach ($this->providerClasses() as $class) {
            try {
                $provider = app($class);

                if ($provider instanceof InventoryContributionProvider) {
                    $registry->register($provider);
                }
            } catch (Throwable) {
                // Read-only display data — a broken provider must not take down the page.
                continue;
            }
        }
    }

    /**
     * @return list<class-string<InventoryContributionProvider>>
     */
    private function providerClasses(): array
    {
        $classes = [];

        foreach ($this->scanPatterns ?? $this->defaultScanPatterns() as $pattern) {
            foreach (glob($pattern) ?: [] as $file) {
                $config = require $file;

                if (! is_array($config)) {
                    continue;
                }

                foreach ($config['contribution_providers'] ?? [] as $class) {
                    if (is_string($class) && is_subclass_of($class, InventoryContributionProvider::class)) {
                        $classes[$class] = $class;
                    }
                }
            }
        }

        return array_values($classes);
    }

    /**
     * @return list<string>
     */
    private function defaultScanPatterns(): array
    {
        return [
            base_path('app/Modules/*/*/Config/inventory.php'),
            base_path('extensions/*/*/Config/inventory.php'),
        ];
    }
}
