<?php

namespace App\Base\Foundation\PHPStan;

use App\Base\Foundation\ApplicationTopology;
use App\Base\Foundation\ModuleManifest\ModuleManifest;
use App\Base\Foundation\ModuleManifest\ModuleManifestReader;
use App\Base\Foundation\Services\ModuleTableOwnershipScanner;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;
use PHPStan\Type\ObjectType;

/** @implements Rule<Expr> */
final class DomainTableBoundaryRule implements Rule
{
    /** @var array<string, string>|null */
    private ?array $moduleRoots = null;

    /** @var array<string, ModuleManifest>|null */
    private ?array $manifests = null;

    /** @var array<string, list<string>>|null */
    private ?array $tableOwners = null;

    /** @param array<string, array<string, string>> $allowlist Module ID => table => reason. */
    public function __construct(private string $projectRoot, private array $allowlist = [])
    {
        foreach ($allowlist as $tables) {
            foreach ($tables as $reason) {
                if (trim($reason) === '') {
                    throw new \InvalidArgumentException('Domain table exceptions require a reason.');
                }
            }
        }
    }

    public function getNodeType(): string
    {
        return Expr::class;
    }

    /** @return list<IdentifierRuleError> */
    public function processNode(Node $node, Scope $scope): array
    {
        $consumer = $this->domainModuleForFile($scope->getFile());
        if ($consumer === null) {
            return [];
        }

        $tables = $this->tablesNamedBy($node, $scope);
        if ($tables === []) {
            return [];
        }

        $errors = [];
        foreach ($tables as $table) {
            foreach ($this->tableOwners()[$table] ?? [] as $ownerModule) {
                $owner = $this->domainModule($ownerModule);
                if ($owner === null || $owner['domain'] === $consumer['domain']) {
                    continue;
                }

                if ($this->isSharedWith($table, $ownerModule, $consumer['module'])
                    || isset($this->allowlist[$consumer['module']][$table])) {
                    continue;
                }

                $errors[] = RuleErrorBuilder::message(sprintf(
                    'Module [%s] must not query table [%s] owned by module [%s]; the owner must export it through extra.blb.shared-tables and the consumer must directly require the owner.',
                    $consumer['module'],
                    $table,
                    $ownerModule,
                ))
                    ->identifier('blb.domainTableBoundary')
                    ->line($node->getStartLine())
                    ->build();
            }
        }

        return $errors;
    }

    /** @return list<string> */
    private function tablesNamedBy(Node $node, Scope $scope): array
    {
        if ($node instanceof StaticCall
            && $node->class instanceof Name
            && $node->name instanceof Identifier
            && $scope->resolveName($node->class) === DB::class
        ) {
            $method = $node->name->toString();

            if ($method === 'table') {
                return $this->constantStrings($node, $scope);
            }

            if ($method === 'select') {
                return $this->tablesInSql($this->constantStrings($node, $scope));
            }
        }

        if ($node instanceof MethodCall
            && $node->name instanceof Identifier
            && $node->name->toString() === 'from'
            && (new ObjectType(QueryBuilder::class))->isSuperTypeOf($scope->getType($node->var))->yes()
        ) {
            return $this->constantStrings($node, $scope);
        }

        return [];
    }

    /** @return list<string> */
    private function constantStrings(StaticCall|MethodCall $node, Scope $scope): array
    {
        if (! isset($node->getArgs()[0])) {
            return [];
        }

        return array_values(array_unique(array_map(
            fn ($value): string => $value->getValue(),
            $scope->getType($node->getArgs()[0]->value)->getConstantStrings(),
        )));
    }

    /**
     * @param  list<string>  $statements
     * @return list<string>
     */
    private function tablesInSql(array $statements): array
    {
        $tables = [];
        foreach ($statements as $statement) {
            if (preg_match_all('/\b(?:from|join)\s+[`"]?([a-zA-Z_][a-zA-Z0-9_]*)[`"]?/i', $statement, $matches)) {
                $tables = [...$tables, ...$matches[1]];
            }
        }

        return array_values(array_unique($tables));
    }

    private function isSharedWith(string $table, string $ownerModule, string $consumerModule): bool
    {
        $owner = $this->manifests()[$ownerModule] ?? null;
        $consumer = $this->manifests()[$consumerModule] ?? null;

        return $owner !== null
            && $consumer !== null
            && in_array($table, $owner->sharedTables, true)
            && array_key_exists($ownerModule, $consumer->requiresModules);
    }

    /** @return array{domain: string, module: string}|null */
    private function domainModuleForFile(string $file): ?array
    {
        $normalized = $this->normalizePath($file);
        foreach ($this->moduleRoots() as $module => $path) {
            $root = $this->normalizePath($path);
            if ($normalized === $root || str_starts_with($normalized, $root.'/')) {
                return $this->domainModule($module);
            }
        }

        return null;
    }

    /** @return array{domain: string, module: string}|null */
    private function domainModule(string $module): ?array
    {
        $path = $this->moduleRoots()[$module] ?? null;
        if ($path === null) {
            return null;
        }

        $prefix = $this->normalizePath($this->projectRoot.'/'.ApplicationTopology::DOMAINS).'/';
        $path = $this->normalizePath($path);
        if (! str_starts_with($path, $prefix)) {
            return null;
        }

        return [
            'domain' => explode('/', substr($path, strlen($prefix)))[0],
            'module' => $module,
        ];
    }

    /** @return array<string, string> */
    private function moduleRoots(): array
    {
        return $this->moduleRoots ??= $this->reader()->moduleRootsIncludingDisabledDomains();
    }

    /** @return array<string, ModuleManifest> */
    private function manifests(): array
    {
        if ($this->manifests !== null) {
            return $this->manifests;
        }

        $this->manifests = [];
        foreach ($this->reader()->allIncludingDisabledDomains() as $manifest) {
            $this->manifests[$manifest->module] = $manifest;
        }

        return $this->manifests;
    }

    /** @return array<string, list<string>> */
    private function tableOwners(): array
    {
        return $this->tableOwners ??= (new ModuleTableOwnershipScanner)->scan($this->moduleRoots());
    }

    private function reader(): ModuleManifestReader
    {
        return new ModuleManifestReader(
            array_map(
                fn (string $root): string => $this->projectRoot.'/'.$root,
                ApplicationTopology::relativeRoots(),
            ),
            projectRoot: $this->projectRoot,
        );
    }

    private function normalizePath(string $path): string
    {
        return rtrim(str_replace('\\', '/', $path), '/');
    }
}
