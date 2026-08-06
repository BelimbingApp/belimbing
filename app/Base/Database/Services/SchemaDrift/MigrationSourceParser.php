<?php

namespace App\Base\Database\Services\SchemaDrift;

use PhpParser\Error;
use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\Parser;
use PhpParser\ParserFactory;
use ReflectionClass;

final class MigrationSourceParser
{
    private ParserContext $context;

    private BlueprintProcessor $blueprintProcessor;

    private SchemaCallProcessor $schemaCallProcessor;

    private MutationDetector $mutationDetector;

    private readonly Parser $parser;

    public function __construct(?Parser $parser = null)
    {
        $this->parser = $parser ?? (new ParserFactory)->createForHostVersion();
    }

    public function parse(string $path): ParsedMigration
    {
        $contents = file_get_contents($path);

        if ($contents === false) {
            return new ParsedMigration(
                $this->relativePath($path),
                pathinfo($path, PATHINFO_FILENAME),
                unreadable: [['line' => 1, 'reason' => 'Migration source could not be read.']],
            );
        }

        return $this->parseContents($contents, $this->relativePath($path));
    }

    public function parseContents(string $contents, string $path = 'migration.php'): ParsedMigration
    {
        $this->context = new ParserContext;
        $this->context->evaluator = new StaticExpressionEvaluator;

        try {
            $nodes = $this->resolvedNodes($contents);
        } catch (Error $e) {
            return new ParsedMigration(
                $path,
                pathinfo($path, PATHINFO_FILENAME),
                unreadable: [[
                    'line' => max(1, $e->getStartLine()),
                    'reason' => 'PHP source could not be parsed: '.$e->getRawMessage(),
                ]],
            );
        }

        $class = (new NodeFinder)->findFirstInstanceOf($nodes, Node\Stmt\Class_::class);

        if (! $class instanceof Node\Stmt\Class_) {
            return new ParsedMigration(
                $path,
                pathinfo($path, PATHINFO_FILENAME),
                unreadable: [['line' => 1, 'reason' => 'No migration class was found.']],
            );
        }

        $this->loadClassMembers($class);

        $this->blueprintProcessor = new BlueprintProcessor($this->context);
        $this->schemaCallProcessor = new SchemaCallProcessor($this->context);
        $this->mutationDetector = new MutationDetector($this->context);
        $this->context->processMethod = $this->processMethod(...);
        $this->context->processStatements = $this->processStatements(...);

        $up = $this->context->methods['up'] ?? null;
        if (! $up instanceof Node\Stmt\ClassMethod || $up->stmts === null) {
            $this->context->unreadable[] = [
                'line' => $class->getStartLine(),
                'reason' => 'Migration has no source-resolvable up() method.',
            ];
        } else {
            $this->processMethod($up, []);
        }

        return new ParsedMigration(
            $path,
            pathinfo($path, PATHINFO_FILENAME),
            $this->context->operations,
            $this->context->unreadable,
        );
    }

    /** @return list<Node\Stmt> */
    private function resolvedNodes(string $contents): array
    {
        $nodes = $this->parser->parse($contents) ?? [];
        $traverser = new NodeTraverser;
        $traverser->addVisitor(new NameResolver);

        return $traverser->traverse($nodes);
    }

    private function loadClassMembers(Node\Stmt\Class_ $class): void
    {
        $constants = [];
        foreach ($class->getConstants() as $constantStatement) {
            foreach ($constantStatement->consts as $constant) {
                $constants[$constant->name->toString()] = $this->context->evaluator->evaluate($constant->value, []);
                $this->context->evaluator->setConstants($constants);
            }
        }

        foreach ($class->getMethods() as $method) {
            $this->context->methods[strtolower($method->name->toString())] = $method;
        }

        foreach ($class->stmts as $statement) {
            if (! $statement instanceof Node\Stmt\TraitUse) {
                continue;
            }

            foreach ($statement->traits as $traitName) {
                $resolved = $traitName->getAttribute('resolvedName');
                $this->loadTraitMembers($resolved instanceof Node\Name ? $resolved->toString() : $traitName->toString());
            }
        }
    }

    private function loadTraitMembers(string $trait): void
    {
        if (! trait_exists($trait)) {
            $this->context->unreadable[] = [
                'line' => 1,
                'reason' => sprintf('Migration trait [%s] could not be loaded for static schema inspection.', $trait),
            ];

            return;
        }

        $path = (new ReflectionClass($trait))->getFileName();
        if (! is_string($path) || ($contents = file_get_contents($path)) === false) {
            $this->context->unreadable[] = [
                'line' => 1,
                'reason' => sprintf('Migration trait [%s] source could not be read.', $trait),
            ];

            return;
        }

        try {
            $nodes = $this->resolvedNodes($contents);
        } catch (Error $e) {
            $this->context->unreadable[] = [
                'line' => max(1, $e->getStartLine()),
                'reason' => sprintf('Migration trait [%s] could not be parsed: %s', $trait, $e->getRawMessage()),
            ];

            return;
        }

        $traitNode = (new NodeFinder)->findFirstInstanceOf($nodes, Node\Stmt\Trait_::class);
        if (! $traitNode instanceof Node\Stmt\Trait_) {
            return;
        }

        foreach ($traitNode->getMethods() as $method) {
            $this->context->methods[strtolower($method->name->toString())] ??= $method;
        }
    }

    /** @param  array<int|string, mixed>  $arguments */
    private function processMethod(Node\Stmt\ClassMethod $method, array $arguments): void
    {
        $name = strtolower($method->name->toString());
        if (isset($this->context->activeMethods[$name]) || $method->stmts === null) {
            return;
        }

        $this->context->activeMethods[$name] = true;
        $this->processStatements($method->stmts, $this->context->evaluator->methodEnvironment($method, $arguments));
        unset($this->context->activeMethods[$name]);
    }

    /** @param  list<Node\Stmt>  $statements @param  array<string, mixed>  $environment */
    private function processStatements(array $statements, array $environment): void
    {
        foreach ($statements as $statement) {
            if ($statement instanceof Node\Stmt\Expression) {
                $environment = $this->processExpressionStatement($statement, $environment);

                continue;
            }

            if ($statement instanceof Node\Stmt\If_) {
                $this->processIfStatement($statement, $environment);
            } elseif ($statement instanceof Node\Stmt\Foreach_) {
                $this->processForeachStatement($statement, $environment);
            } elseif ($statement instanceof Node\Stmt\TryCatch) {
                $this->processTryCatchStatement($statement, $environment);
            } elseif ($statement instanceof Node\Stmt\Switch_) {
                $this->processSwitchStatement($statement, $environment);
            } elseif ($statement instanceof Node\Stmt\For_
                || $statement instanceof Node\Stmt\While_
                || $statement instanceof Node\Stmt\Do_) {
                $this->processLoopStatement($statement, $environment);
            }
        }
    }

    /** @param  array<string, mixed>  $environment @return array<string, mixed> */
    private function processExpressionStatement(Node\Stmt\Expression $statement, array $environment): array
    {
        $this->processExpression($statement->expr, $environment);

        if ($statement->expr instanceof Node\Expr\Assign
            && $statement->expr->var instanceof Node\Expr\Variable
            && is_string($statement->expr->var->name)) {
            $environment[$statement->expr->var->name] = $this->context->evaluator->evaluate($statement->expr->expr, $environment);
        }

        return $environment;
    }

    /** @param  array<string, mixed>  $environment */
    private function processIfStatement(Node\Stmt\If_ $statement, array $environment): void
    {
        $branches = $statement->stmts;
        foreach ($statement->elseifs as $elseif) {
            array_push($branches, ...$elseif->stmts);
        }
        if ($statement->else !== null) {
            array_push($branches, ...$statement->else->stmts);
        }

        if ($this->mutationDetector->containsSchemaMutation($branches, $environment)
            && ! ($statement->elseifs === []
                && $statement->else === null
                && $this->mutationDetector->isSchemaPredicate($statement->cond))
            && ! $this->mutationDetector->isLegacyRenameGuard($statement)) {
            $this->context->reportUnreadable($statement, 'Schema mutation inside a runtime-dependent conditional cannot be replayed statically.');
        }

        $this->processStatements($statement->stmts, $environment);
        foreach ($statement->elseifs as $elseif) {
            $this->processStatements($elseif->stmts, $environment);
        }
        if ($statement->else !== null) {
            $this->processStatements($statement->else->stmts, $environment);
        }
    }

    /** @param  array<string, mixed>  $environment */
    private function processForeachStatement(Node\Stmt\Foreach_ $statement, array $environment): void
    {
        $values = $this->context->evaluator->evaluate($statement->expr, $environment);

        if (! is_array($values) && $this->mutationDetector->containsSchemaMutation($statement->stmts, $environment)) {
            $this->context->reportUnreadable($statement, 'Schema mutation inside a foreach loop has a runtime-dependent iterable.');
        }

        if (! is_array($values)) {
            return;
        }

        foreach ($values as $key => $value) {
            $loopEnvironment = $environment;
            if ($statement->valueVar instanceof Node\Expr\Variable && is_string($statement->valueVar->name)) {
                $loopEnvironment[$statement->valueVar->name] = $value;
            }
            if ($statement->keyVar instanceof Node\Expr\Variable && is_string($statement->keyVar->name)) {
                $loopEnvironment[$statement->keyVar->name] = $key;
            }
            $this->processStatements($statement->stmts, $loopEnvironment);
        }
    }

    /** @param  array<string, mixed>  $environment */
    private function processTryCatchStatement(Node\Stmt\TryCatch $statement, array $environment): void
    {
        $this->processStatements($statement->stmts, $environment);
        foreach ($statement->catches as $catch) {
            if ($this->mutationDetector->containsSchemaMutation($catch->stmts, $environment)) {
                $this->context->reportUnreadable($catch, 'Schema mutation inside a catch branch cannot be replayed statically.');
            }
            $this->processStatements($catch->stmts, $environment);
        }
        if ($statement->finally !== null) {
            $this->processStatements($statement->finally->stmts, $environment);
        }
    }

    /** @param  array<string, mixed>  $environment */
    private function processSwitchStatement(Node\Stmt\Switch_ $statement, array $environment): void
    {
        $caseStatements = [];
        foreach ($statement->cases as $case) {
            array_push($caseStatements, ...$case->stmts);
            $this->processStatements($case->stmts, $environment);
        }
        if ($this->mutationDetector->containsSchemaMutation($caseStatements, $environment)) {
            $this->context->reportUnreadable($statement, 'Schema mutation inside a runtime-dependent switch cannot be replayed statically.');
        }
    }

    /** @param  array<string, mixed>  $environment */
    private function processLoopStatement(Node\Stmt\For_|Node\Stmt\While_|Node\Stmt\Do_ $statement, array $environment): void
    {
        if ($this->mutationDetector->containsSchemaMutation($statement->stmts, $environment)) {
            $this->context->reportUnreadable($statement, 'Schema mutation inside a runtime-dependent loop cannot be replayed statically.');
        }
    }

    /** @param  array<string, mixed>  $environment */
    private function processExpression(Node\Expr $expression, array $environment): void
    {
        if ($expression instanceof Node\Expr\Assign) {
            $this->processExpression($expression->expr, $environment);

            return;
        }

        if ($expression instanceof Node\Expr\StaticCall) {
            $this->schemaCallProcessor->processStaticCallExpression($expression, $environment);

            return;
        }

        if ($expression instanceof Node\Expr\MethodCall) {
            $this->blueprintProcessor->processMethodCallExpression($expression, $environment);
        }
    }

    private function relativePath(string $path): string
    {
        $base = rtrim(base_path(), DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR;
        $normalized = str_replace('\\', '/', $path);
        $normalizedBase = str_replace('\\', '/', $base);

        return str_starts_with($normalized, $normalizedBase)
            ? substr($normalized, strlen($normalizedBase))
            : $normalized;
    }
}
