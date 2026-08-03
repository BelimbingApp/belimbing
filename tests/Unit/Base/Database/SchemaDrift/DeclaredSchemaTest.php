<?php

use App\Base\Database\Services\SchemaDrift\DeclaredIndex;
use App\Base\Database\Services\SchemaDrift\DeclaredIndexType;
use App\Base\Database\Services\SchemaDrift\DeclaredSchema;
use App\Base\Database\Services\SchemaDrift\ParsedMigration;
use App\Base\Database\Services\SchemaDrift\TableOperation;

it('preserves add drop and re-add order while reconstructing declared schema', function (): void {
    $unique = new DeclaredIndex(['email'], DeclaredIndexType::UNIQUE);
    $schema = DeclaredSchema::fromMigrations([
        new ParsedMigration('one.php', 'one', [
            TableOperation::createTable('widgets', 1),
            TableOperation::addColumn('widgets', 'email', 2),
            TableOperation::addIndex('widgets', $unique, 3),
            TableOperation::dropColumn('widgets', 'email', 4),
            TableOperation::addColumn('widgets', 'email_address', 5),
            TableOperation::renameColumn('widgets', 'email_address', 'email', 6),
            TableOperation::addIndex('widgets', $unique, 7),
        ]),
        new ParsedMigration('two.php', 'two', [
            TableOperation::dropIndex('widgets', $unique, 8),
            TableOperation::addIndex('widgets', $unique, 9),
        ]),
    ]);

    expect($schema->unreadable)->toBe([])
        ->and(array_keys($schema->tables['widgets']['columns']))->toBe(['email'])
        ->and(array_keys($schema->tables['widgets']['indexes']))->toBe([$unique->signature()])
        ->and($schema->tables['widgets']['indexes'][$unique->signature()]['migration'])->toBe('two.php')
        ->and($schema->tables['widgets']['indexes'][$unique->signature()]['line'])->toBe(9);
});
