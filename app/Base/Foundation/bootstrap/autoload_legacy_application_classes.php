<?php

use App\Base\Foundation\Compatibility\LegacyApplicationClassMap;

/**
 * Resolve durable class references written before ADR 0001's namespace move.
 *
 * This is intentionally a one-way compatibility boundary: new code authors
 * only the App\Core, App\Domains, and App\Extensions namespaces, while queued
 * jobs, polymorphic rows, workflow declarations, and immutable migrations may
 * still ask Composer for their former class names.
 */
spl_autoload_register(static function (string $legacyClass): void {
    $currentClass = LegacyApplicationClassMap::canonical($legacyClass);

    if ($currentClass === $legacyClass) {
        return;
    }

    $exists = class_exists($currentClass)
        || interface_exists($currentClass)
        || trait_exists($currentClass)
        || enum_exists($currentClass);

    $legacyExists = class_exists($legacyClass, false)
        || interface_exists($legacyClass, false)
        || trait_exists($legacyClass, false)
        || enum_exists($legacyClass, false);

    if ($exists && ! $legacyExists) {
        class_alias($currentClass, $legacyClass);
    }
});
