<?php

namespace App\Base\Database\Exceptions;

use App\Base\Database\Enums\DatabaseErrorCode;
use App\Base\Foundation\Exceptions\BlbInvariantViolationException;

/**
 * Thrown when dev seeders declare a circular dependency graph.
 */
final class CircularSeederDependencyException extends BlbInvariantViolationException
{
    public static function forClasses(array $seederClasses): self
    {
        return new self(
            'Circular dependency detected among dev seeders: '.implode(', ', $seederClasses),
            DatabaseErrorCode::CIRCULAR_SEEDER_DEPENDENCY,
            ['seeder_classes' => $seederClasses],
        );
    }
}
