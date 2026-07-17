<?php

namespace App\Base\Livewire;

use RuntimeException;
use Throwable;

/**
 * A Livewire component class could not be loaded during discovery and was
 * skipped — typically a class linking against a contract its sibling repo
 * doesn't ship yet after a partial cross-repo update.
 */
class ComponentDiscoveryException extends RuntimeException
{
    public static function classFailedToLoad(string $class, Throwable $previous): self
    {
        return new self(
            "Livewire component {$class} failed to load and was skipped: {$previous->getMessage()}",
            previous: $previous,
        );
    }
}
