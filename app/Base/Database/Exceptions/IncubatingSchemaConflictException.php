<?php

namespace App\Base\Database\Exceptions;

use App\Base\Foundation\Exceptions\BlbConfigurationException;

final class IncubatingSchemaConflictException extends BlbConfigurationException
{
    /**
     * @param  list<array{table: string, declared_by: string, owned_by: string|null}>  $conflicts
     */
    public static function forLiveTableOwnership(array $conflicts): self
    {
        $details = collect($conflicts)
            ->map(function (array $conflict): string {
                $owner = $conflict['owned_by'] ?? 'no registered migration';

                return "{$conflict['table']} is declared by {$conflict['declared_by']} but owned by {$owner}";
            })
            ->implode(', ');

        return new self(
            'Cannot rebuild incubating schema because a declared table name conflicts with live schema: '.$details.'. Rename the new table, use a forward migration, or repair incorrect registry provenance before retrying.'
        );
    }

    /**
     * @param  list<array{table: string, migration: string}>  $conflicts
     */
    public static function forAppliedForwardMigrations(array $conflicts): self
    {
        $details = collect($conflicts)
            ->map(fn (array $conflict): string => "{$conflict['migration']} references {$conflict['table']}")
            ->implode(', ');

        return new self(
            'Cannot rebuild incubating schema because an applied stable migration may contain later changes to it: '.$details.'. Rebuilding only the original migration would omit that mature schema. Use a new forward migration, rebuild from a canonical baseline, or follow the documented recovery/ADR process; do not retrofit replay metadata merely to bypass this guard.'
        );
    }

    /**
     * @param  array<string, list<string>>  $migrations
     */
    public static function forUnsafeReplayMigrations(array $migrations): self
    {
        $details = collect($migrations)
            ->map(fn (array $operations, string $migration): string => $migration.' uses '.implode(', ', $operations))
            ->implode('; ');

        return new self(
            'Cannot rebuild incubating schema because a migration marked ReplaysAfterIncubatingSchema is not data-only: '.$details.'. Remove schema operations or make the complete disposable migration chain incubating before retrying.'
        );
    }

    public static function forUnreadableAppliedMigration(string $path): self
    {
        return new self(
            "Cannot rebuild incubating schema because applied migration source could not be read: {$path}. Restore readable migration source before retrying."
        );
    }
}
