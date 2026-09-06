<?php

namespace App\Base\Foundation\PHPStan;

use App\Base\Foundation\ApplicationTopology;
use App\Base\Foundation\ModuleManifest\ModuleManifest;
use App\Base\Foundation\ModuleManifest\ModuleManifestReader;
use Illuminate\Database\Eloquent\Model;
use PhpParser\Node;
use PhpParser\Node\ComplexType;
use PhpParser\Node\Identifier;
use PhpParser\Node\IntersectionType;
use PhpParser\Node\Name;
use PhpParser\Node\NullableType;
use PhpParser\Node\UnionType;
use PHPStan\Analyser\Scope;
use PHPStan\Node\InClassMethodNode;
use PHPStan\Reflection\ReflectionProvider;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;
use PHPStan\Type\ObjectType;

/**
 * Refuse Domain code that type-hints another Domain's Eloquent model.
 *
 * Depend on an exported contract/interface from the owning module instead.
 * Extension code is exempt.
 *
 * @implements Rule<InClassMethodNode>
 */
final class DomainEloquentModelBoundaryRule implements Rule
{
    /** @var list<ModuleManifest>|null */
    private ?array $manifests = null;

    public function __construct(
        private string $projectRoot,
        private ReflectionProvider $reflectionProvider,
    ) {}

    public function getNodeType(): string
    {
        return InClassMethodNode::class;
    }

    /** @return list<IdentifierRuleError> */
    public function processNode(Node $node, Scope $scope): array
    {
        $consumer = $this->domainOwner($scope->getFile());
        if ($consumer === null) {
            return [];
        }

        $errors = [];
        $original = $node->getOriginalNode();

        foreach ($original->getParams() as $param) {
            if ($param->type === null) {
                continue;
            }

            foreach ($this->classNamesInType($param->type, $scope) as $className) {
                $error = $this->errorForForeignModel($consumer, $className, $param->getStartLine());
                if ($error !== null) {
                    $errors[] = $error;
                }
            }
        }

        $returnType = $original->getReturnType();
        if ($returnType !== null) {
            foreach ($this->classNamesInType($returnType, $scope) as $className) {
                $error = $this->errorForForeignModel($consumer, $className, $returnType->getStartLine());
                if ($error !== null) {
                    $errors[] = $error;
                }
            }
        }

        return $errors;
    }

    /**
     * @return list<string>
     */
    private function classNamesInType(Node $type, Scope $scope): array
    {
        if ($type instanceof NullableType) {
            return $this->classNamesInType($type->type, $scope);
        }

        if ($type instanceof UnionType || $type instanceof IntersectionType) {
            $names = [];
            foreach ($type->types as $inner) {
                $names = [...$names, ...$this->classNamesInType($inner, $scope)];
            }

            return $names;
        }

        if ($type instanceof Identifier || $type instanceof ComplexType) {
            return [];
        }

        if ($type instanceof Name) {
            return [$scope->resolveName($type)];
        }

        return [];
    }

    private function errorForForeignModel(array $consumer, string $className, int $line): ?IdentifierRuleError
    {
        if (! $this->reflectionProvider->hasClass($className)) {
            return null;
        }

        $reflection = $this->reflectionProvider->getClass($className);
        if (! (new ObjectType(Model::class))->isSuperTypeOf(new ObjectType($className))->yes()) {
            return null;
        }

        $file = $reflection->getFileName();
        if ($file === null) {
            return null;
        }

        $owner = $this->domainOwner($file);
        if ($owner === null || $owner['domain'] === $consumer['domain']) {
            return null;
        }

        return RuleErrorBuilder::message(sprintf(
            'Module [%s] must not reference Eloquent model [%s] owned by module [%s]; use an exported contract instead.',
            $consumer['module'],
            $className,
            $owner['module'],
        ))
            ->identifier('blb.domainEloquentModelBoundary')
            ->line($line)
            ->build();
    }

    /**
     * @return array{domain: string, module: string}|null
     */
    private function domainOwner(string $file): ?array
    {
        $normalized = str_replace('\\', '/', $file);
        $domainsRoot = $this->projectRoot.'/'.ApplicationTopology::DOMAINS.'/';
        $extensionsRoot = $this->projectRoot.'/'.ApplicationTopology::EXTENSIONS.'/';

        if (str_starts_with($normalized, $extensionsRoot)) {
            return null;
        }

        if (! str_starts_with($normalized, $domainsRoot)) {
            return null;
        }

        $relative = substr($normalized, strlen($domainsRoot));
        $parts = explode('/', $relative);
        if (count($parts) < 2) {
            return null;
        }

        $domain = $parts[0];
        $moduleDirectory = $domainsRoot.$parts[0].'/'.$parts[1];

        foreach ($this->manifests() as $manifest) {
            if ($manifest->path === $moduleDirectory) {
                return ['domain' => $domain, 'module' => $manifest->module];
            }
        }

        return [
            'domain' => $domain,
            'module' => strtolower($parts[0]).'/'.strtolower($parts[1]),
        ];
    }

    /** @return list<ModuleManifest> */
    private function manifests(): array
    {
        return $this->manifests ??= (new ModuleManifestReader(array_map(
            fn (string $root): string => $this->projectRoot.'/'.$root,
            ApplicationTopology::relativeRoots(),
        )))->allIncludingDisabledDomains();
    }
}
