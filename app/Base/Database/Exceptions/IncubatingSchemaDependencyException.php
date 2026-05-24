<?php

namespace App\Base\Database\Exceptions;

use App\Base\Foundation\Exceptions\BlbConfigurationException;

final class IncubatingSchemaDependencyException extends BlbConfigurationException
{
    /**
     * @param  list<array{table: string, column: string, foreign_table: string}>  $dependencies
     */
    public static function forStableDependents(array $dependencies): self
    {
        $details = collect($dependencies)
            ->map(fn (array $dependency): string => "{$dependency['table']}.{$dependency['column']} -> {$dependency['foreign_table']}")
            ->implode(', ');

        return new self(
            'Cannot rebuild incubating schema because stable tables depend on it: '.$details.'. Mark the dependent migration incubating too, split the migration, or add a forward migration instead.'
        );
    }
}
