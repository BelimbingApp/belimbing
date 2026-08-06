<?php

namespace App\Base\Database\Services\SchemaDrift;

/**
 * Represents a Blueprint instance resolved from a Schema::create/table
 * closure parameter during static source analysis.
 */
final readonly class BlueprintReference
{
    public function __construct(public string $table) {}
}
