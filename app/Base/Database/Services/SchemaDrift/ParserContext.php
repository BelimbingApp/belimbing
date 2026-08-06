<?php

namespace App\Base\Database\Services\SchemaDrift;

use PhpParser\Node;

/**
 * Shared mutable state and service wiring for the migration source parser
 * and its collaborators.
 */
final class ParserContext
{
    /** @var list<TableOperation> */
    public array $operations = [];

    /** @var list<array{line: int, reason: string}> */
    public array $unreadable = [];

    public StaticExpressionEvaluator $evaluator;

    /** @var array<string, Node\Stmt\ClassMethod> */
    public array $methods = [];

    /** @var array<string, true> */
    public array $activeMethods = [];

    /** @var callable(Node\Stmt\ClassMethod, array<int|string, mixed>): void */
    public $processMethod;

    /** @var callable(list<Node\Stmt>, array<string, mixed>): void */
    public $processStatements;

    public function addOperation(TableOperation $operation): void
    {
        $this->operations[] = $operation;
    }

    public function reportUnreadable(Node $node, string $reason): void
    {
        $this->unreadable[] = [
            'line' => max(1, $node->getStartLine()),
            'reason' => $reason,
        ];
    }
}
