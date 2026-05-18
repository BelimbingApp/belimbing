<?php

use App\Base\Database\Enums\DatabaseErrorCode;
use App\Base\Database\Exceptions\PostgresIdentifierTooLongException;
use App\Base\Database\Postgres\GuardedPostgresConnection;
use App\Base\Database\Postgres\PostgresIdentifierGuard;

it('allows PostgreSQL identifiers at the byte limit', function (): void {
    $identifier = str_repeat('a', PostgresIdentifierGuard::MAX_IDENTIFIER_BYTES);

    PostgresIdentifierGuard::assertSqlIdentifiersWithinLimit(
        'create table "'.$identifier.'" ("id" bigint not null)'
    );

    expect(true)->toBeTrue();
});

it('rejects PostgreSQL identifiers over the byte limit', function (): void {
    $identifier = str_repeat('a', PostgresIdentifierGuard::MAX_IDENTIFIER_BYTES + 1);

    try {
        PostgresIdentifierGuard::assertSqlIdentifiersWithinLimit(
            'alter table "people" add constraint "'.$identifier.'" unique ("id")'
        );
    } catch (PostgresIdentifierTooLongException $exception) {
        expect($exception->reasonCode)->toBe(DatabaseErrorCode::DATABASE_IDENTIFIER_TOO_LONG)
            ->and($exception->context)->toBe([
                'identifier' => $identifier,
                'byte_length' => PostgresIdentifierGuard::MAX_IDENTIFIER_BYTES + 1,
                'max_bytes' => PostgresIdentifierGuard::MAX_IDENTIFIER_BYTES,
            ]);

        return;
    }

    $this->fail('Expected PostgreSQL identifier length guard to throw.');
});

it('rejects unquoted PostgreSQL identifiers over the byte limit', function (): void {
    $identifier = str_repeat('a', PostgresIdentifierGuard::MAX_IDENTIFIER_BYTES + 1);

    expect(fn () => PostgresIdentifierGuard::assertSqlIdentifiersWithinLimit(
        'create table '.$identifier.' (id bigint not null)'
    ))
        ->toThrow(PostgresIdentifierTooLongException::class);
});

it('counts identifier bytes rather than characters', function (): void {
    $identifier = str_repeat('é', 32);

    expect(strlen($identifier))->toBe(64);

    expect(fn () => PostgresIdentifierGuard::assertSqlIdentifiersWithinLimit(
        'create table "'.$identifier.'" ("id" bigint not null)'
    ))
        ->toThrow(PostgresIdentifierTooLongException::class);
});

it('ignores quoted content inside SQL strings and comments', function (): void {
    $identifier = str_repeat('a', PostgresIdentifierGuard::MAX_IDENTIFIER_BYTES + 1);

    PostgresIdentifierGuard::assertSqlIdentifiersWithinLimit(<<<SQL
        -- "{$identifier}"
        /* "{$identifier}" */
        select '"{$identifier}"' as "safe_alias", $$"{$identifier}"$$ as "safe_literal"
    SQL);

    expect(true)->toBeTrue();
});

it('guards SQL executed through the PostgreSQL connection', function (): void {
    $identifier = str_repeat('a', PostgresIdentifierGuard::MAX_IDENTIFIER_BYTES + 1);
    $connection = new GuardedPostgresConnection(fn () => throw new RuntimeException('PDO should not resolve while pretending'));

    expect(fn () => $connection->pretend(
        fn (GuardedPostgresConnection $database) => $database->statement('create table "'.$identifier.'" ("id" bigint not null)')
    ))->toThrow(PostgresIdentifierTooLongException::class);
});
