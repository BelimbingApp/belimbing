<?php

namespace App\Base\Database\Postgres;

use App\Base\Database\Exceptions\PostgresIdentifierTooLongException;

final class PostgresIdentifierGuard
{
    public const MAX_IDENTIFIER_BYTES = 63;

    /**
     * Runtime guarding is limited to schema-changing statements; normal
     * browsing queries should not pay the identifier parser cost.
     */
    public static function shouldInspectSql(string $sql): bool
    {
        return in_array(self::firstKeyword($sql), ['alter', 'create', 'drop'], true);
    }

    public static function assertSqlIdentifiersWithinLimit(string $sql): void
    {
        foreach (self::identifiers($sql) as $identifier) {
            $byteLength = strlen($identifier);

            if ($byteLength > self::MAX_IDENTIFIER_BYTES) {
                throw PostgresIdentifierTooLongException::forIdentifier(
                    $identifier,
                    $byteLength,
                    self::MAX_IDENTIFIER_BYTES,
                );
            }
        }
    }

    private static function firstKeyword(string $sql): string
    {
        $length = strlen($sql);
        $offset = 0;

        while ($offset < $length) {
            $char = $sql[$offset];

            if (ctype_space($char)) {
                $offset++;

                continue;
            }

            $commentEnd = self::skipComment($sql, $offset, $length);
            if ($commentEnd !== null) {
                $offset = $commentEnd;

                continue;
            }

            if (self::isBareIdentifierStart($char)) {
                [$keyword] = self::readBareIdentifier($sql, $offset, $length);

                return strtolower($keyword);
            }

            return '';
        }

        return '';
    }

    /**
     * @return list<string>
     */
    private static function identifiers(string $sql): array
    {
        $identifiers = [];
        $length = strlen($sql);
        $offset = 0;

        while ($offset < $length) {
            $char = $sql[$offset];

            $commentEnd = self::skipComment($sql, $offset, $length);
            if ($commentEnd !== null) {
                $offset = $commentEnd;

                continue;
            }

            if ($char === "'") {
                $offset = self::skipSingleQuotedString($sql, $offset + 1, $length);

                continue;
            }

            if ($char === '$' && preg_match('/\\G\\$[A-Za-z_]\\w*\\$|\\G\\$\\$/', $sql, $matches, 0, $offset) === 1) {
                $delimiter = $matches[0];
                $end = strpos($sql, $delimiter, $offset + strlen($delimiter));
                $offset = $end === false ? $length : $end + strlen($delimiter);

                continue;
            }

            if ($char === '"') {
                [$identifier, $offset] = self::readQuotedIdentifier($sql, $offset + 1, $length);
                $identifiers[] = $identifier;

                continue;
            }

            if (self::isBareIdentifierStart($char)) {
                [$identifier, $offset] = self::readBareIdentifier($sql, $offset, $length);
                $identifiers[] = $identifier;

                continue;
            }

            $offset++;
        }

        return $identifiers;
    }

    private static function skipComment(string $sql, int $offset, int $length): ?int
    {
        $char = $sql[$offset];
        $next = $sql[$offset + 1] ?? '';

        if ($char === '-' && $next === '-') {
            $newline = strpos($sql, "\n", $offset + 2);

            return $newline === false ? $length : $newline + 1;
        }

        if ($char === '/' && $next === '*') {
            $end = strpos($sql, '*/', $offset + 2);

            return $end === false ? $length : $end + 2;
        }

        return null;
    }

    private static function skipSingleQuotedString(string $sql, int $offset, int $length): int
    {
        while ($offset < $length) {
            if ($sql[$offset] === "'" && ($sql[$offset + 1] ?? '') === "'") {
                $offset += 2;

                continue;
            }

            if ($sql[$offset] === "'") {
                return $offset + 1;
            }

            $offset++;
        }

        return $length;
    }

    /**
     * @return array{string, int}
     */
    private static function readQuotedIdentifier(string $sql, int $offset, int $length): array
    {
        $identifier = '';

        while ($offset < $length) {
            if ($sql[$offset] === '"' && ($sql[$offset + 1] ?? '') === '"') {
                $identifier .= '"';
                $offset += 2;

                continue;
            }

            if ($sql[$offset] === '"') {
                return [$identifier, $offset + 1];
            }

            $identifier .= $sql[$offset];
            $offset++;
        }

        return [$identifier, $length];
    }

    /**
     * @return array{string, int}
     */
    private static function readBareIdentifier(string $sql, int $offset, int $length): array
    {
        $identifier = '';

        while ($offset < $length && self::isBareIdentifierPart($sql[$offset])) {
            $identifier .= $sql[$offset];
            $offset++;
        }

        return [$identifier, $offset];
    }

    private static function isBareIdentifierStart(string $char): bool
    {
        return ($char >= 'A' && $char <= 'Z')
            || ($char >= 'a' && $char <= 'z')
            || $char === '_';
    }

    private static function isBareIdentifierPart(string $char): bool
    {
        return self::isBareIdentifierStart($char)
            || ($char >= '0' && $char <= '9')
            || $char === '$';
    }
}
