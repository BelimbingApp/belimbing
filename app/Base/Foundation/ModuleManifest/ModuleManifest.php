<?php

namespace App\Base\Foundation\ModuleManifest;

/**
 * Parsed `extra.blb` block from a module's composer.json.
 *
 * Built by ModuleManifestReader. Immutable value object — consumers
 * read it and decide what to do; the manifest itself does nothing.
 */
final readonly class ModuleManifest
{
    /**
     * @param  array<string, string>  $requiresModules
     * @param  array<string, string>  $optionalModules
     * @param  list<string>  $publishesEvents
     * @param  list<string>  $consumesEvents
     */
    public function __construct(
        public string $name,
        public string $module,
        public string $path,
        public string $version = '',
        public string $description = '',
        public array $requiresModules = [],
        public array $optionalModules = [],
        public array $publishesEvents = [],
        public array $consumesEvents = [],
    ) {}
}
