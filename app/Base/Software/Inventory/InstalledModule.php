<?php

namespace App\Base\Software\Inventory;

/**
 * A Module as it appears inside an InstalledBundle on the Software Inventory.
 *
 * Modules are ownership boundaries, not install units: an operator never installs
 * or removes a Module on its own — it rides along with the Bundle that delivers it.
 * Immutable value object built by SoftwareInventoryService from a ModuleManifest.
 */
final readonly class InstalledModule
{
    /**
     * @param  array<string, string>  $requiresModules
     * @param  array<string, string>  $optionalModules
     * @param  list<string>  $publishesEvents
     * @param  list<string>  $consumesEvents
     */
    public function __construct(
        public string $module,
        public string $name,
        public string $path,
        public string $version = '',
        public string $description = '',
        public array $requiresModules = [],
        public array $optionalModules = [],
        public array $publishesEvents = [],
        public array $consumesEvents = [],
    ) {}

    public function label(): string
    {
        return $this->module !== '' ? $this->module : $this->name;
    }
}
