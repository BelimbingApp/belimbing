<?php

namespace App\Base\Database\Services\SchemaDrift;

/** Classifies raw statements the schema comparator intentionally ignores. */
final class RawSchemaStatementClassifier
{
    public static function isOutsideComparison(string $sql): bool
    {
        return preg_match('/^\s*ALTER\s+TABLE\s+\S+\s+ADD\s+CONSTRAINT\s+\S+\s+CHECK\b/i', $sql) === 1
            || preg_match('/^\s*CREATE\s+(?:OR\s+REPLACE\s+)?(?:TRIGGER|FUNCTION)\b/i', $sql) === 1
            || preg_match('/^\s*DROP\s+(?:TRIGGER|FUNCTION)\b/i', $sql) === 1;
    }
}
